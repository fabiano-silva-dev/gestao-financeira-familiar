<?php

namespace Tests\Feature;

use App\Enums\AccountMovementType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\BankStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardInvoicePayment;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\FinancialImportProcessor;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreditCardInvoicePaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_bank_entry_suggests_matching_open_invoice_payment(): void
    {
        [$user, $workspace, $account, $invoice] = $this->openInvoiceScenario();
        $entry = $this->bankEntry($workspace, $account, '-2000.00', '2026-09-15', 'PAGAMENTO NUBANK');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reconciliation/index')
                ->where('entries.0.id', $entry->id)
                ->where('entries.0.is_likely_invoice_payment', true)
                ->where('entries.0.has_suggestion', true)
                ->where('entries.0.candidates.0.invoice_id', $invoice->id)
                ->where('entries.0.candidates.0.is_invoice_payment', true)
                ->where('entries.0.candidates.0.is_suggestion', true)
                ->where('entries.0.suggestion_description', 'Possível pagamento da Fatura Nubank Setembro — R$ 2.000,00')
                ->where('entries.0.invoice_total_amount', '2000.00')
                ->where('entries.0.invoice_outstanding_amount', '2000.00')
            );
    }

    public function test_confirming_invoice_payment_from_bank_entry_settles_invoice_without_new_expense(): void
    {
        [$user, $workspace, $account, $invoice] = $this->openInvoiceScenario();
        $entry = $this->bankEntry($workspace, $account, '-2000.00', '2026-09-15', 'PAGAMENTO NUBANK');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.invoice-payment', $entry), [
                'credit_card_invoice_id' => $invoice->id,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        $entry->refresh();
        $payment = $invoice->payments()->sole();
        $movement = $payment->movement()->sole();

        $this->assertTrue($entry->is_reconciled);
        $this->assertSame($movement->id, $entry->account_movement_id);
        $this->assertTrue($movement->is_reconciled);
        $this->assertSame(AccountMovementType::CardPayment, $movement->type);
        $this->assertNull($movement->financial_transaction_id);
        $this->assertSame(CreditCardInvoiceStatus::Paid, $invoice->status);
        $this->assertSame('2000.00', $invoice->paid_amount);
        $this->assertDatabaseCount('credit_card_invoice_payments', 1);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertSame(
            FinancialTransactionType::Expense,
            FinancialTransaction::query()->sole()->type,
        );
        $this->assertDatabaseCount('account_movements', 1);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.current_balance', '3000.00'));
    }

    public function test_partial_bank_payment_leaves_invoice_partially_paid(): void
    {
        [$user, $workspace, $account, $invoice] = $this->openInvoiceScenario();
        $entry = $this->bankEntry($workspace, $account, '-1500.00', '2026-09-15', 'PAGAMENTO NUBANK');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.invoice-payment', $entry), [
                'credit_card_invoice_id' => $invoice->id,
            ])
            ->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertSame(CreditCardInvoiceStatus::Partial, $invoice->status);
        $this->assertSame('1500.00', $invoice->paid_amount);
        $this->assertNull($invoice->paid_at);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_second_bank_payment_completes_partially_paid_invoice(): void
    {
        [$user, $workspace, $account, $invoice] = $this->openInvoiceScenario();
        $first = $this->bankEntry($workspace, $account, '-1500.00', '2026-09-15', 'PAGAMENTO NUBANK');
        $second = $this->bankEntry($workspace, $account, '-500.00', '2026-09-20', 'PAGAMENTO NUBANK');
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.invoice-payment', $first), [
            'credit_card_invoice_id' => $invoice->id,
        ])->assertSessionHasNoErrors();
        $request->post(route('reconciliation.invoice-payment', $second), [
            'credit_card_invoice_id' => $invoice->id,
        ])->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertSame(CreditCardInvoiceStatus::Paid, $invoice->status);
        $this->assertSame('2000.00', $invoice->paid_amount);
        $this->assertDatabaseCount('credit_card_invoice_payments', 2);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('account_movements', 2);
    }

    public function test_imported_movement_reconciles_with_already_registered_payment(): void
    {
        [$user, $workspace, $account, $invoice] = $this->openInvoiceScenario();
        $this->closeAndPay($user, $workspace, $invoice, $account, '2000.00', '2026-09-15');
        $entry = $this->bankEntry($workspace, $account, '-2000.00', '2026-09-15', 'PAGAMENTO NUBANK');
        $payment = $invoice->payments()->sole();
        $movement = $payment->movement()->sole();

        $this->assertFalse($movement->is_reconciled);
        $this->assertDatabaseCount('account_movements', 1);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.invoice-payment', $entry), [
                'credit_card_invoice_id' => $invoice->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($entry->fresh()->is_reconciled);
        $this->assertSame($movement->id, $entry->fresh()->account_movement_id);
        $this->assertTrue($movement->fresh()->is_reconciled);
        $this->assertDatabaseCount('credit_card_invoice_payments', 1);
        $this->assertDatabaseCount('account_movements', 1);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_processing_the_same_bank_entry_again_does_not_duplicate_payment(): void
    {
        [$user, $workspace, $account, $invoice] = $this->openInvoiceScenario();
        $entry = $this->bankEntry($workspace, $account, '-2000.00', '2026-09-15', 'PAGAMENTO NUBANK');
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.invoice-payment', $entry), [
            'credit_card_invoice_id' => $invoice->id,
        ])->assertSessionHasNoErrors();
        $request->post(route('reconciliation.invoice-payment', $entry), [
            'credit_card_invoice_id' => $invoice->id,
        ])->assertSessionHasErrors('entry');

        $this->assertDatabaseCount('credit_card_invoice_payments', 1);
        $this->assertDatabaseCount('account_movements', 1);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_invoice_from_another_workspace_cannot_be_reconciled(): void
    {
        [$user, $workspace, $account] = $this->openInvoiceScenario();
        $entry = $this->bankEntry($workspace, $account, '-2000.00', '2026-09-15', 'PAGAMENTO NUBANK');
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherCard = CreditCard::factory()->for($otherWorkspace)->create(['name' => 'Nubank']);
        $otherInvoice = CreditCardInvoice::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'credit_card_id' => $otherCard->id,
            'reference_month' => '2026-09-01',
            'closing_date' => '2026-09-08',
            'due_date' => '2026-09-15',
            'calculated_amount' => '2000.00',
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Open->value,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.invoice-payment', $entry), [
                'credit_card_invoice_id' => $otherInvoice->id,
            ])
            ->assertSessionHasErrors('credit_card_invoice_id');

        $this->assertFalse($entry->fresh()->is_reconciled);
        $this->assertDatabaseCount('credit_card_invoice_payments', 0);
    }

    public function test_bank_payment_can_be_registered_for_card_before_invoice_exists(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
            'opening_balance' => '5000.00',
        ]);
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
            'institution' => 'Nubank',
            'payment_account_id' => $account->id,
            'invoice_payment_method' => PaymentMethod::Pix,
        ]);
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-800.00',
            '2026-09-15',
            'PAGAMENTO NUBANK',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.card-payment', $entry), [
                'credit_card_id' => $card->id,
            ])
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $payment = CreditCardInvoicePayment::query()->sole();
        $movement = $payment->movement()->sole();

        $this->assertTrue($entry->is_reconciled);
        $this->assertSame($card->id, $payment->credit_card_id);
        $this->assertNull($payment->credit_card_invoice_id);
        $this->assertSame('800.00', $payment->amount);
        $this->assertSame(AccountMovementType::CardPayment, $movement->type);
        $this->assertTrue($movement->is_reconciled);
        $this->assertNull($movement->financial_transaction_id);
        $this->assertDatabaseCount('financial_transactions', 0);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account, [
                'view' => 'reconciled',
            ])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.is_invoice_payment', true)
                ->where('entries.0.card_name', 'Nubank Fabiano')
                ->where('entries.0.invoice_label', 'Aguardando fatura')
                ->where('entries.0.invoice_status_label', 'Aguardando vínculo')
            );
    }

    public function test_processor_registers_unassigned_card_payment_when_card_is_unambiguous(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
        ]);
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
            'institution' => 'Nubank',
            'payment_account_id' => $account->id,
            'invoice_payment_method' => PaymentMethod::Pix,
        ]);
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-350.00',
            '2026-09-15',
            'PAGAMENTO NUBANK FABIANO',
        );

        app(FinancialImportProcessor::class)->process(
            $workspace,
            $entry->financialImport()->firstOrFail(),
            $user,
        );

        $payment = CreditCardInvoicePayment::query()->sole();

        $this->assertTrue($entry->fresh()->is_reconciled);
        $this->assertSame($card->id, $payment->credit_card_id);
        $this->assertNull($payment->credit_card_invoice_id);
        $this->assertSame('350.00', $payment->amount);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_importing_invoice_automatically_links_compatible_pending_card_payment(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
        ]);
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
            'institution' => 'Nubank',
            'closing_day' => 8,
            'due_day' => 15,
            'payment_account_id' => $account->id,
            'invoice_payment_method' => PaymentMethod::Pix,
        ]);
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-50.00',
            '2026-10-15',
            'PAGAMENTO NUBANK FABIANO',
        );
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.card-payment', $entry), [
            'credit_card_id' => $card->id,
        ])->assertSessionHasNoErrors();

        $payment = CreditCardInvoicePayment::query()->sole();
        $this->assertNull($payment->credit_card_invoice_id);

        $request->post(route('imports.card-statements.store'), [
            'credit_card_id' => $card->id,
            'reference_month' => '2026-10',
            'amount_sign' => 'positive',
            'file' => UploadedFile::fake()->createWithContent(
                'fatura-outubro.csv',
                "Data;Estabelecimento;Valor;Identificador\n10/09/2026;LOJA TESTE;50,00;linha-001\n",
            ),
        ])->assertSessionHasNoErrors();

        $invoice = CreditCardInvoice::query()->sole();
        $payment->refresh();
        $invoice->refresh();

        $this->assertSame($invoice->id, $payment->credit_card_invoice_id);
        $this->assertSame('50.00', $invoice->paid_amount);
        $this->assertSame(CreditCardInvoiceStatus::Paid, $invoice->status);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_pending_card_payment_can_be_linked_manually_to_invoice(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Mercado Pago Fabiano',
            'payment_account_id' => $account->id,
            'invoice_payment_method' => PaymentMethod::Pix,
        ]);
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-30.00',
            '2026-09-10',
            'PAGAMENTO MERCADO PAGO',
        );
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.card-payment', $entry), [
            'credit_card_id' => $card->id,
        ])->assertSessionHasNoErrors();

        $invoice = CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => '2026-09-01',
            'closing_date' => '2026-09-08',
            'due_date' => '2026-09-15',
            'calculated_amount' => '100.00',
            'statement_amount' => '100.00',
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Open,
        ]);
        $payment = CreditCardInvoicePayment::query()->sole();

        $request->get(route('credit-card-invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('unlinkedPayments.0.id', $payment->id)
                ->where('unlinkedPayments.0.amount', '30.00')
            );

        $request->post(
            route('credit-card-invoices.payments.link', [$invoice, $payment]),
        )->assertSessionHasNoErrors();

        $this->assertSame(
            $invoice->id,
            $payment->fresh()->credit_card_invoice_id,
        );
        $this->assertSame('30.00', $invoice->fresh()->paid_amount);
        $this->assertSame(
            CreditCardInvoiceStatus::Partial,
            $invoice->fresh()->status,
        );
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_invoice_payment_cannot_be_marked_as_transfer(): void
    {
        [$user, $workspace, $account] = $this->openInvoiceScenario();
        $destination = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-2000.00', '2026-09-15', 'PAGAMENTO NUBANK');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.transfer', $entry), [
                'counterpart_account_id' => $destination->id,
            ])
            ->assertSessionHasErrors('entry');

        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('account_movements', 0);
        $this->assertFalse($entry->fresh()->is_reconciled);
    }

    /**
     * @return array{User, Workspace, FinancialAccount, CreditCardInvoice}
     */
    private function openInvoiceScenario(): array
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
            'opening_balance' => '5000.00',
        ]);
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank',
            'institution' => 'Nubank',
            'closing_day' => 8,
            'due_day' => 15,
            'payment_account_id' => $account->id,
            'invoice_payment_method' => PaymentMethod::Pix,
        ]);
        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                'type' => FinancialTransactionType::Expense->value,
                'transaction_date' => '2026-08-20',
                'description' => 'Compras do mês',
                'amount' => '2000.00',
                'financial_account_id' => null,
                'credit_card_id' => $card->id,
                'category_id' => null,
                'family_member_id' => null,
                'payment_method' => PaymentMethod::CreditCard->value,
                'payee_name' => 'Loja',
                'payment_instructions' => null,
                'due_date' => null,
                'status' => FinancialTransactionStatus::Confirmed->value,
                'notes' => null,
                'installment_count' => 1,
            ])
            ->assertSessionHasNoErrors();

        $invoice = FinancialTransaction::query()->sole()->installments()->sole()->invoice;
        $this->assertNotNull($invoice);

        return [$user, $workspace, $account, $invoice];
    }

    private function closeAndPay(
        User $user,
        Workspace $workspace,
        CreditCardInvoice $invoice,
        FinancialAccount $account,
        string $amount,
        string $paidOn,
    ): void {
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->patch(route('credit-card-invoices.close', $invoice), [
            'statement_amount' => $invoice->calculated_amount,
        ])->assertSessionHasNoErrors();

        $request->post(route('credit-card-invoices.pay', $invoice), [
            'financial_account_id' => $account->id,
            'paid_on' => $paidOn,
            'amount' => $amount,
            'payment_method' => PaymentMethod::Pix->value,
            'notes' => null,
        ])->assertSessionHasNoErrors();
    }

    /**
     * @return array{User, Workspace}
     */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }

    /**
     * @param  array<string, int|string>  $extra
     * @return array<string, int|string>
     */
    private function workbenchQuery(FinancialAccount $account, array $extra = []): array
    {
        return [
            'account' => $account->id,
            'period' => '2026-09',
            ...$extra,
        ];
    }

    private function bankEntry(
        Workspace $workspace,
        FinancialAccount $account,
        string $amount,
        string $occurredOn = '2026-09-15',
        string $description = 'PAGAMENTO NUBANK',
    ): BankStatementEntry {
        $this->sequence++;
        $suffix = str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT);
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'type' => FinancialImportType::Ofx,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => "extrato-{$suffix}.ofx",
            'file_hash' => hash('sha256', "file-{$workspace->id}-{$suffix}"),
            'deduplication_key' => hash('sha256', "import-{$workspace->id}-{$suffix}"),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'imported_at' => now(),
        ]);

        return BankStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'financial_account_id' => $account->id,
            'external_id' => "fit-{$suffix}",
            'deduplication_key' => hash('sha256', "entry-{$workspace->id}-{$suffix}"),
            'occurred_on' => $occurredOn,
            'amount' => $amount,
            'transaction_type' => 'DEBIT',
            'description' => $description,
            'memo' => null,
            'is_reconciled' => false,
        ]);
    }
}
