<?php

namespace Tests\Feature;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialImport;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CardStatementReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_guest_cannot_reconcile_card_statement_entry(): void
    {
        $this->post(route('credit-card-invoices.statement-entries.reconcile', [1, 1]))
            ->assertRedirect(route('login'));
    }

    public function test_invoice_prioritizes_compatible_installment_by_number_date_and_description(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $invoice = $this->invoice($workspace, $card);
        $entry = $this->statementEntry(
            $workspace,
            $card,
            $invoice,
            '89.90',
            '2026-09-10',
            'Vôlei Lidiane',
            2,
            10,
        );
        $best = $this->installment(
            $workspace,
            $card,
            $invoice,
            '89.90',
            '2026-09-10',
            'Vôlei Lidiane',
            2,
            10,
        );
        $this->installment(
            $workspace,
            $card,
            $invoice,
            '89.90',
            '2026-08-01',
            'Outra compra',
            2,
            10,
        );
        $this->installment(
            $workspace,
            $card,
            $invoice,
            '90.00',
            '2026-09-10',
            'Valor diferente',
            2,
            10,
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('credit-card-invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credit-card-invoices/show')
                ->where('invoice.statement_entries.0.id', $entry->id)
                ->where(
                    'invoice.statement_entries.0.candidates.0.installment_id',
                    $best->id,
                )
                ->where('invoice.statement_entries.0.candidates.0.confidence', 'high')
                ->where('invoice.statement_entries.0.candidates.0.is_suggestion', true)
                ->has('invoice.statement_entries.0.candidates', 2));
    }

    public function test_user_can_reconcile_statement_entry_without_creating_another_expense(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $invoice = $this->invoice($workspace, $card);
        $installment = $this->installment($workspace, $card, $invoice);
        $entry = $this->statementEntry($workspace, $card, $invoice);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(
                route('credit-card-invoices.statement-entries.reconcile', [$invoice, $entry]),
                ['transaction_installment_id' => $installment->id],
            )
            ->assertRedirect(route('credit-card-invoices.show', $invoice))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertTrue($entry->is_reconciled);
        $this->assertSame($installment->id, $entry->transaction_installment_id);
        $this->assertSame($user->id, $entry->reconciled_by);
        $this->assertNotNull($entry->reconciled_at);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('transaction_installments', 1);
        $this->assertDatabaseCount('credit_card_invoices', 1);
    }

    public function test_reconciliation_requires_same_invoice_amount_and_installment_identification(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $invoice = $this->invoice($workspace, $card);
        $entry = $this->statementEntry($workspace, $card, $invoice);
        $wrongAmount = $this->installment(
            $workspace,
            $card,
            $invoice,
            '89.89',
        );
        $wrongNumber = $this->installment(
            $workspace,
            $card,
            $invoice,
            installmentNumber: 3,
            totalInstallments: 10,
        );
        $otherInvoice = $this->invoice($workspace, $card, '2026-11-01');
        $otherInvoiceInstallment = $this->installment(
            $workspace,
            $card,
            $otherInvoice,
        );
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(
            route('credit-card-invoices.statement-entries.reconcile', [$invoice, $entry]),
            ['transaction_installment_id' => $wrongAmount->id],
        )->assertSessionHasErrors('transaction_installment_id');

        $request->post(
            route('credit-card-invoices.statement-entries.reconcile', [$invoice, $entry]),
            ['transaction_installment_id' => $wrongNumber->id],
        )->assertSessionHasErrors('transaction_installment_id');

        $request->post(
            route('credit-card-invoices.statement-entries.reconcile', [$invoice, $entry]),
            ['transaction_installment_id' => $otherInvoiceInstallment->id],
        )->assertSessionHasErrors('transaction_installment_id');

        $this->assertFalse($entry->fresh()->is_reconciled);
    }

    public function test_same_installment_cannot_be_reconciled_twice(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $invoice = $this->invoice($workspace, $card);
        $installment = $this->installment($workspace, $card, $invoice);
        $firstEntry = $this->statementEntry($workspace, $card, $invoice);
        $secondEntry = $this->statementEntry($workspace, $card, $invoice);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(
            route('credit-card-invoices.statement-entries.reconcile', [$invoice, $firstEntry]),
            ['transaction_installment_id' => $installment->id],
        )->assertSessionHasNoErrors();

        $request->post(
            route('credit-card-invoices.statement-entries.reconcile', [$invoice, $secondEntry]),
            ['transaction_installment_id' => $installment->id],
        )->assertSessionHasErrors('transaction_installment_id');

        $this->assertTrue($firstEntry->fresh()->is_reconciled);
        $this->assertFalse($secondEntry->fresh()->is_reconciled);
    }

    public function test_user_can_undo_card_statement_reconciliation(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $invoice = $this->invoice($workspace, $card);
        $installment = $this->installment($workspace, $card, $invoice);
        $entry = $this->statementEntry($workspace, $card, $invoice);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(
            route('credit-card-invoices.statement-entries.reconcile', [$invoice, $entry]),
            ['transaction_installment_id' => $installment->id],
        )->assertSessionHasNoErrors();
        $request->delete(
            route(
                'credit-card-invoices.statement-entries.reconciliation.destroy',
                [$invoice, $entry],
            ),
        )->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertFalse($entry->is_reconciled);
        $this->assertNull($entry->transaction_installment_id);
        $this->assertNull($entry->reconciled_by);
        $this->assertNull($entry->reconciled_at);
    }

    public function test_installment_from_another_workspace_cannot_be_reconciled(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $invoice = $this->invoice($workspace, $card);
        $entry = $this->statementEntry($workspace, $card, $invoice);
        $otherWorkspace = Workspace::factory()->create();
        $otherCard = CreditCard::factory()->for($otherWorkspace)->create();
        $otherInvoice = $this->invoice($otherWorkspace, $otherCard);
        $otherInstallment = $this->installment(
            $otherWorkspace,
            $otherCard,
            $otherInvoice,
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(
                route('credit-card-invoices.statement-entries.reconcile', [$invoice, $entry]),
                ['transaction_installment_id' => $otherInstallment->id],
            )
            ->assertSessionHasErrors('transaction_installment_id');

        $this->assertFalse($entry->fresh()->is_reconciled);
    }

    public function test_reconciled_purchase_allows_metadata_but_blocks_financial_structure_changes(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 5,
            'due_day' => 12,
        ]);
        $service = app(FinancialEntryService::class);
        $data = $this->cardPurchaseData($card);
        $purchase = $service->create($workspace, $data);
        $installment = $purchase->installments()
            ->where('installment_number', 2)
            ->firstOrFail();
        $invoice = $installment->invoice()->firstOrFail();
        $entry = $this->statementEntry(
            $workspace,
            $card,
            $invoice,
            $installment->amount,
            '2026-09-20',
            'Compra parcelada',
            2,
            10,
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(
                route('credit-card-invoices.statement-entries.reconcile', [$invoice, $entry]),
                ['transaction_installment_id' => $installment->id],
            )
            ->assertSessionHasNoErrors();

        $updated = $service->update($purchase->fresh(), [
            ...$data,
            'description' => 'Compra parcelada categorizada',
            'notes' => 'Informação complementar',
        ]);

        $this->assertSame('Compra parcelada categorizada', $updated->description);
        $this->assertSame($installment->id, $entry->fresh()->transaction_installment_id);
        $this->assertDatabaseCount('transaction_installments', 10);

        try {
            $service->update($updated, [
                ...$data,
                'description' => 'Mudança financeira inválida',
                'amount' => '1100.00',
            ]);
            $this->fail('A alteração financeira deveria exigir a remoção da conciliação.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reconciliation', $exception->errors());
        }

        $this->assertSame('1000.00', $purchase->fresh()->amount);
        $this->assertSame(
            'Compra parcelada categorizada',
            $purchase->fresh()->description,
        );
        $this->assertSame($installment->id, $entry->fresh()->transaction_installment_id);
    }

    /** @return array{User, Workspace} */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }

    private function invoice(
        Workspace $workspace,
        CreditCard $card,
        string $referenceMonth = '2026-10-01',
    ): CreditCardInvoice {
        return CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => $referenceMonth,
            'closing_date' => $referenceMonth === '2026-10-01'
                ? '2026-09-25'
                : '2026-10-25',
            'due_date' => $referenceMonth === '2026-10-01'
                ? '2026-10-10'
                : '2026-11-10',
            'calculated_amount' => '0.00',
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Open,
        ]);
    }

    private function installment(
        Workspace $workspace,
        CreditCard $card,
        CreditCardInvoice $invoice,
        string $amount = '89.90',
        string $transactionDate = '2026-09-10',
        string $description = 'Vôlei Lidiane',
        int $installmentNumber = 2,
        int $totalInstallments = 10,
    ): TransactionInstallment {
        $transaction = $workspace->financialTransactions()->create([
            'type' => FinancialTransactionType::Expense,
            'transaction_date' => $transactionDate,
            'competence_date' => $transactionDate,
            'description' => $description,
            'amount' => $amount,
            'credit_card_id' => $card->id,
            'payment_method' => PaymentMethod::CreditCard,
            'status' => FinancialTransactionStatus::Confirmed,
            'origin' => FinancialTransactionOrigin::Manual,
        ]);

        return $transaction->installments()->create([
            'workspace_id' => $workspace->id,
            'credit_card_invoice_id' => $invoice->id,
            'installment_number' => $installmentNumber,
            'total_installments' => $totalInstallments,
            'amount' => $amount,
            'competence_month' => '2026-09-01',
            'due_date' => $invoice->due_date,
            'expected_payment_date' => $invoice->due_date,
            'status' => TransactionInstallmentStatus::Open,
        ]);
    }

    private function statementEntry(
        Workspace $workspace,
        CreditCard $card,
        CreditCardInvoice $invoice,
        string $amount = '89.90',
        string $purchasedOn = '2026-09-10',
        string $description = 'Vôlei Lidiane',
        ?int $installmentNumber = 2,
        ?int $totalInstallments = 10,
    ): CardStatementEntry {
        $this->sequence++;
        $suffix = str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT);
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'type' => FinancialImportType::CardStatement,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => "fatura-{$suffix}.csv",
            'file_hash' => hash('sha256', "file-{$workspace->id}-{$suffix}"),
            'deduplication_key' => hash('sha256', "import-{$workspace->id}-{$suffix}"),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'imported_at' => now(),
        ]);

        return CardStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'credit_card_id' => $card->id,
            'credit_card_invoice_id' => $invoice->id,
            'purchased_on' => $purchasedOn,
            'description' => $description,
            'amount' => $amount,
            'installment_number' => $installmentNumber,
            'total_installments' => $totalInstallments,
            'deduplication_key' => hash('sha256', "entry-{$workspace->id}-{$suffix}"),
            'is_reconciled' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function cardPurchaseData(CreditCard $card): array
    {
        return [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-20',
            'competence_date' => '2026-09-20',
            'description' => 'Compra parcelada',
            'amount' => '1000.00',
            'financial_account_id' => null,
            'credit_card_id' => $card->id,
            'installment_count' => 10,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::CreditCard->value,
            'payee_name' => 'Loja teste',
            'payment_instructions' => null,
            'due_date' => null,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ];
    }
}
