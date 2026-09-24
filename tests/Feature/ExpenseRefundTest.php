<?php

namespace Tests\Feature;

use App\Enums\AccountMovementType;
use App\Enums\ExpenseRefundDestination;
use App\Enums\ExpenseRefundOrigin;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\BankStatementEntry;
use App\Models\CreditCard;
use App\Models\ExpenseRefund;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\CreditCardInvoiceService;
use App\Services\Finance\ExpenseRefundService;
use App\Services\Finance\FinancialEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpenseRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_account_refund_zeroes_net_expense(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Mercado Pago',
            'opening_balance' => '0.00',
        ]);
        $expense = $this->createExpense(
            $workspace,
            $account,
            '200.00',
            'Compra devolvida',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.refunds.store', $expense), [
                'amount' => '200.00',
                'refunded_on' => '2026-09-22',
                'destination_type' => ExpenseRefundDestination::Account->value,
                'destination_account_id' => $account->id,
                'credit_card_invoice_id' => null,
                'notes' => 'Devolução integral',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('200.00', $expense->fresh()->amount);
        $this->assertDatabaseHas('expense_refunds', [
            'financial_transaction_id' => $expense->id,
            'amount' => '200.00',
            'destination_account_id' => $account->id,
        ]);
        $this->assertDatabaseHas('account_movements', [
            'financial_account_id' => $account->id,
            'amount' => '200.00',
            'type' => AccountMovementType::Refund->value,
        ]);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->get(route('transactions.edit', $expense))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.original_amount', '200.00')
                ->where('entry.refunded_amount', '200.00')
                ->where('entry.net_amount', '0.00')
                ->where('entry.refund_status', 'refunded')
                ->where('entry.refund_status_label', 'Reembolsada')
            );

        $request->get(route('dashboard', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.expenses', '0.00')
                ->where('metrics.income', '0.00')
            );
    }

    public function test_partial_refund_reduces_managerial_expense_to_remaining_amount(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $expense = $this->createExpense(
            $workspace,
            $account,
            '500.00',
            'Compra parcialmente devolvida',
        );

        app(ExpenseRefundService::class)->register(
            $workspace,
            $expense,
            $user,
            [
                'amount' => '120.00',
                'refunded_on' => '2026-09-22',
                'destination_type' => ExpenseRefundDestination::Account->value,
                'destination_account_id' => $account->id,
                'notes' => null,
            ],
        );

        $summary = app(ExpenseRefundService::class)->summary($expense->fresh());

        $this->assertSame('500.00', $summary['original_amount']);
        $this->assertSame('120.00', $summary['refunded_amount']);
        $this->assertSame('380.00', $summary['net_amount']);
        $this->assertSame('partial', $summary['refund_status']);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('dashboard', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.expenses', '380.00')
            );
    }

    public function test_account_refund_does_not_create_income(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $expense = $this->createExpense(
            $workspace,
            $account,
            '200.00',
            'Despesa original',
        );

        app(ExpenseRefundService::class)->register(
            $workspace,
            $expense,
            $user,
            [
                'amount' => '75.00',
                'refunded_on' => '2026-09-22',
                'destination_type' => ExpenseRefundDestination::Account->value,
                'destination_account_id' => $account->id,
                'notes' => null,
            ],
        );

        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseMissing('financial_transactions', [
            'workspace_id' => $workspace->id,
            'type' => FinancialTransactionType::Income->value,
        ]);
        $this->assertDatabaseHas('account_movements', [
            'type' => AccountMovementType::Refund->value,
            'amount' => '75.00',
        ]);
    }

    public function test_refund_to_another_account_does_not_reduce_card_invoice(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $destination = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Mercado Pago',
        ]);
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 5,
            'due_day' => 12,
        ]);
        $purchase = $this->createCardExpense($workspace, $card, '200.00');
        $invoice = $purchase->installments()->sole()->invoice;
        $invoiceService = app(CreditCardInvoiceService::class);

        app(ExpenseRefundService::class)->register(
            $workspace,
            $purchase,
            $user,
            [
                'amount' => '200.00',
                'refunded_on' => '2026-09-22',
                'destination_type' => ExpenseRefundDestination::Account->value,
                'destination_account_id' => $destination->id,
                'notes' => 'Reembolso fora do cartão',
            ],
        );

        $invoice->refresh();

        $this->assertSame('200.00', $invoiceService->totalAmount($invoice));
        $this->assertSame('200.00', $invoiceService->outstandingAmount($invoice));
        $this->assertNull(ExpenseRefund::query()->sole()->credit_card_invoice_id);
        $this->assertDatabaseHas('account_movements', [
            'financial_account_id' => $destination->id,
            'type' => AccountMovementType::Refund->value,
            'amount' => '200.00',
        ]);
    }

    public function test_direct_card_refund_reduces_invoice_without_bank_movement(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 5,
            'due_day' => 12,
        ]);
        $purchase = $this->createCardExpense($workspace, $card, '200.00');
        $invoice = $purchase->installments()->sole()->invoice;
        $invoiceService = app(CreditCardInvoiceService::class);

        app(ExpenseRefundService::class)->register(
            $workspace,
            $purchase,
            $user,
            [
                'amount' => '120.00',
                'refunded_on' => '2026-09-22',
                'destination_type' => ExpenseRefundDestination::CreditCard->value,
                'destination_account_id' => null,
                'credit_card_invoice_id' => $invoice->id,
                'notes' => 'Estorno diretamente no cartão',
            ],
        );

        $invoice->refresh();

        $this->assertSame('80.00', $invoiceService->totalAmount($invoice));
        $this->assertSame('80.00', $invoiceService->outstandingAmount($invoice));
        $this->assertSame('120.00', $invoiceService->refundAmount($invoice));
        $this->assertDatabaseCount('account_movements', 0);
        $this->assertDatabaseHas('expense_refunds', [
            'credit_card_id' => $card->id,
            'credit_card_invoice_id' => $invoice->id,
            'amount' => '120.00',
        ]);
    }

    public function test_bank_credit_can_be_reconciled_as_refund(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Mercado Pago',
        ]);
        $expense = $this->createExpense(
            $workspace,
            $account,
            '200.00',
            'LOJA TESTE',
        );
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '200.00',
            '2026-09-22',
            'CREDITO MERCADO PAGO LOJA TESTE',
        );

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->get(route('reconciliation.index', [
            'account' => $account->id,
            'period' => '2026-09',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.is_likely_refund', false)
            );

        $request->getJson(route('reconciliation.refund-candidates', $entry))
            ->assertOk()
            ->assertJsonPath('candidates.0.is_refund', true)
            ->assertJsonPath('candidates.0.is_suggestion', true)
            ->assertJsonPath('candidates.0.transaction_id', $expense->id);

        $request->post(route('reconciliation.refund', $entry), [
            'financial_transaction_id' => $expense->id,
        ])->assertSessionHasNoErrors();

        $refund = ExpenseRefund::query()->sole();
        $movement = $refund->movement()->sole();

        $this->assertTrue($entry->fresh()->is_reconciled);
        $this->assertSame($movement->id, $entry->fresh()->account_movement_id);
        $this->assertTrue($movement->fresh()->is_reconciled);
        $this->assertSame(ExpenseRefundOrigin::BankReconciliation, $refund->origin);
        $this->assertSame($user->id, $refund->created_by);
        $this->assertSame($user->id, $refund->linked_by);
        $this->assertNotNull($refund->linked_at);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_unrelated_income_is_not_suggested_as_expense_refund(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $this->createExpense(
            $workspace,
            $account,
            '150.00',
            'Pix enviado Associacao Voleibol Futuro',
        );
        $this->bankEntry(
            $workspace,
            $account,
            '150.00',
            '2026-09-20',
            'PIX RECEBIDO - USE O CLOSET MODA FEMININA LTDA',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', [
                'account' => $account->id,
                'period' => '2026-09',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.is_likely_refund', false)
                ->where('entries.0.has_suggestion', false)
                ->where('entries.0.suggestion_description', null)
                ->where('entries.0.related_payee_name', null)
            );
    }

    public function test_refund_respects_workspace_isolation(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $expense = $this->createExpense(
            $workspace,
            $account,
            '100.00',
            'Despesa atual',
        );
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.refunds.store', $expense), [
                'amount' => '50.00',
                'refunded_on' => '2026-09-22',
                'destination_type' => ExpenseRefundDestination::Account->value,
                'destination_account_id' => $otherAccount->id,
                'credit_card_invoice_id' => null,
                'notes' => null,
            ])
            ->assertSessionHasErrors('destination_account_id');

        $this->assertDatabaseCount('expense_refunds', 0);
    }

    public function test_same_bank_movement_cannot_be_used_twice_for_refund(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $expense = $this->createExpense(
            $workspace,
            $account,
            '200.00',
            'LOJA DUPLICIDADE',
        );
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '100.00',
            '2026-09-22',
            'DEVOLUCAO LOJA DUPLICIDADE',
        );
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.refund', $entry), [
            'financial_transaction_id' => $expense->id,
        ])->assertSessionHasNoErrors();

        $request->post(route('reconciliation.refund', $entry), [
            'financial_transaction_id' => $expense->id,
        ])->assertSessionHasErrors('entry');

        $this->assertDatabaseCount('expense_refunds', 1);
        $this->assertDatabaseCount('account_movements', 2);
    }

    public function test_ofx_import_leaves_refund_pending_for_manual_identification(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $expense = $this->createExpense(
            $workspace,
            $account,
            '200.00',
            'LOJA IMPORTADA',
        );

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('imports.ofx.store'), [
            'financial_account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent(
                'reembolso.ofx',
                $this->ofxRefundFile(),
            ),
        ])->assertSessionHasNoErrors();

        $import = FinancialImport::query()->sole();
        $entry = BankStatementEntry::query()
            ->where('external_id', 'refund-001')
            ->sole();

        $this->assertFalse($entry->is_reconciled);
        $this->assertDatabaseCount('expense_refunds', 0);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseMissing('financial_transactions', [
            'workspace_id' => $workspace->id,
            'type' => FinancialTransactionType::Income->value,
        ]);
        $this->assertSame(
            0,
            data_get($import->metadata, 'processing_summary.refunds_identified'),
        );
        $this->assertSame(
            0,
            data_get($import->metadata, 'processing_summary.new_transactions_created'),
        );

        $request->getJson(route('reconciliation.refund-candidates', $entry))
            ->assertOk()
            ->assertJsonPath('candidates.0.is_refund', true)
            ->assertJsonPath('candidates.0.transaction_id', $expense->id);
    }

    private function createExpense(
        Workspace $workspace,
        FinancialAccount $account,
        string $amount,
        string $description,
    ): FinancialTransaction {
        return app(FinancialEntryService::class)->create($workspace, [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-20',
            'competence_date' => '2026-09-20',
            'description' => $description,
            'amount' => $amount,
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix->value,
            'payee_name' => $description,
            'payment_instructions' => null,
            'due_date' => null,
            'settled_on' => '2026-09-20',
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);
    }

    private function createCardExpense(
        Workspace $workspace,
        CreditCard $card,
        string $amount,
    ): FinancialTransaction {
        return app(FinancialEntryService::class)->create($workspace, [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-20',
            'competence_date' => '2026-09-20',
            'description' => 'Compra no cartão',
            'amount' => $amount,
            'financial_account_id' => null,
            'credit_card_id' => $card->id,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::CreditCard->value,
            'payee_name' => 'Loja cartão',
            'payment_instructions' => null,
            'due_date' => null,
            'settled_on' => null,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
            'installment_count' => 1,
        ]);
    }

    private function bankEntry(
        Workspace $workspace,
        FinancialAccount $account,
        string $amount,
        string $occurredOn,
        string $description,
    ): BankStatementEntry {
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'type' => FinancialImportType::Ofx,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => 'extrato-reembolso.ofx',
            'file_hash' => hash('sha256', $description.$amount),
            'deduplication_key' => hash('sha256', 'import-'.$description.$amount),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'imported_at' => now(),
        ]);

        return BankStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'financial_account_id' => $account->id,
            'external_id' => hash('sha256', $description),
            'deduplication_key' => hash('sha256', 'entry-'.$description.$amount),
            'occurred_on' => $occurredOn,
            'amount' => $amount,
            'transaction_type' => 'CREDIT',
            'description' => $description,
            'memo' => null,
            'is_reconciled' => false,
        ]);
    }

    private function ofxRefundFile(): string
    {
        return <<<'OFX'
            OFXHEADER:100
            DATA:OFXSGML
            VERSION:102
            SECURITY:NONE
            ENCODING:UTF-8
            CHARSET:UTF-8

            <OFX>
            <BANKMSGSRSV1>
            <STMTTRNRS>
            <STMTRS>
            <CURDEF>BRL
            <BANKACCTFROM>
            <BANKID>748
            <ACCTID>12345-6
            </BANKACCTFROM>
            <BANKTRANLIST>
            <DTSTART>20260901000000[-3:BRT]
            <DTEND>20260930235959[-3:BRT]
            <STMTTRN>
            <TRNTYPE>CREDIT
            <DTPOSTED>20260922120000[-3:BRT]
            <TRNAMT>200.00
            <FITID>refund-001
            <NAME>REEMBOLSO LOJA IMPORTADA
            <MEMO>DEVOLUCAO DE COMPRA
            </STMTTRN>
            </BANKTRANLIST>
            </STMTRS>
            </STMTTRNRS>
            </BANKMSGSRSV1>
            </OFX>
            OFX;
    }

    /** @return array{User, Workspace} */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
