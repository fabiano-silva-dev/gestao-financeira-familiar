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
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\CardStatementMaterializationService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CardInstallmentPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_later_invoice_links_one_cent_difference_to_the_existing_purchase(): void
    {
        [$user, $workspace, $card, $category] = $this->scenario();
        $june = $this->invoice($workspace, $card, '2026-06-01', '2026-06-05', '2026-06-08');
        $first = $this->statement($workspace, $card, $june, $category, '22.79', 1);
        $service = app(CardStatementMaterializationService::class);

        $service->materialize($workspace, $card, $june, $first, $user);

        $july = CreditCardInvoice::query()
            ->where('reference_month', '2026-07-01')
            ->firstOrFail();
        $second = $this->statement($workspace, $card, $july, $category, '22.78', 2);

        $service->materialize($workspace, $card, $july, $second, $user);

        $this->assertSame(1, FinancialTransaction::query()->count());
        $purchase = FinancialTransaction::query()->sole();
        $this->assertSame('182.31', $purchase->amount);
        $this->assertTrue($second->refresh()->is_reconciled);
        $this->assertSame(
            '22.78',
            TransactionInstallment::query()
                ->where('financial_transaction_id', $purchase->id)
                ->where('installment_number', 2)
                ->sole()
                ->amount,
        );
        $this->assertSame(
            $purchase->id,
            $second->transactionInstallment->transaction->id,
        );
    }

    public function test_first_installment_joins_a_plan_started_by_a_later_invoice(): void
    {
        [$user, $workspace, $card, $category] = $this->scenario();
        $july = $this->invoice($workspace, $card, '2026-07-01', '2026-07-05', '2026-07-08');
        $second = $this->statement($workspace, $card, $july, $category, '22.78', 2);
        $service = app(CardStatementMaterializationService::class);

        $service->materialize($workspace, $card, $july, $second, $user);

        $june = $this->invoice($workspace, $card, '2026-06-01', '2026-06-05', '2026-06-08');
        $first = $this->statement($workspace, $card, $june, $category, '22.79', 1);

        $service->materialize($workspace, $card, $june, $first, $user);

        $this->assertSame(1, FinancialTransaction::query()->count());
        $purchase = FinancialTransaction::query()->sole();
        $this->assertSame('182.25', $purchase->amount);
        $this->assertTrue($first->refresh()->is_reconciled);
        $this->assertSame(1, $first->transactionInstallment->installment_number);
        $this->assertSame($purchase->id, $first->transactionInstallment->transaction->id);
    }

    public function test_user_can_merge_a_purchase_duplicated_by_a_later_invoice(): void
    {
        [$user, $workspace, $card, $category] = $this->scenario();
        $june = $this->invoice($workspace, $card, '2026-06-01', '2026-06-05', '2026-06-08');
        $july = $this->invoice($workspace, $card, '2026-07-01', '2026-07-05', '2026-07-08');
        $original = $this->purchase($workspace, $card, $category, 'LOJA Parcela 1 de 8', '182.32');
        $this->installment($original, $june, 1, '22.79');
        $projected = $this->installment($original, $july, 2, '22.79');
        $duplicate = $this->purchase($workspace, $card, $category, 'LOJA Parcela 2 de 8', '182.24');
        $repeated = $this->installment($duplicate, $july, 2, '22.78');
        $statement = $this->statement($workspace, $card, $july, $category, '22.78', 2, 'LOJA Parcela 2 de 8');
        $statement->update([
            'transaction_installment_id' => $repeated->id,
            'is_reconciled' => true,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('transactions.edit', $duplicate))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installmentPlan.canonical_id', $original->id)
                ->where('installmentPlan.is_canonical', false)
                ->where('installmentPlan.duplicates.0.id', $duplicate->id)
            );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.merge-installment-plan', $duplicate))
            ->assertRedirect(route('transactions.edit', $original));

        $this->assertDatabaseMissing('financial_transactions', ['id' => $duplicate->id]);
        $this->assertSame('22.78', $projected->refresh()->amount);
        $this->assertSame($projected->id, $statement->refresh()->transaction_installment_id);
        $this->assertSame('45.57', $original->refresh()->amount);
    }

    public function test_reconciliation_rejects_an_installment_amount_off_by_more_than_one_cent(): void
    {
        [$user, $workspace, $card, $category] = $this->scenario();
        $invoice = $this->invoice($workspace, $card, '2026-07-01', '2026-07-05', '2026-07-08');
        $purchase = $this->purchase($workspace, $card, $category, 'LOJA Parcela 1 de 8', '182.32');
        $installment = $this->installment($purchase, $invoice, 2, '22.79');
        $statement = $this->statement($workspace, $card, $invoice, $category, '22.77', 2, 'LOJA Parcela 2 de 8');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-card-invoices.statement-entries.reconcile', [$invoice, $statement]), [
                'transaction_installment_id' => $installment->id,
            ])
            ->assertSessionHasErrors('transaction_installment_id');

        $this->assertFalse($statement->refresh()->is_reconciled);
        $this->assertSame('22.79', $installment->refresh()->amount);
    }

    public function test_installment_dated_on_the_statement_joins_the_existing_plan(): void
    {
        [$user, $workspace, $card, $category] = $this->scenario();
        $june = $this->invoice($workspace, $card, '2026-06-01', '2026-06-05', '2026-06-08');
        $sixth = $this->statement(
            $workspace,
            $card,
            $june,
            $category,
            '71.90',
            6,
            'Servico Educ*Ntstore - Parcela 6/10',
            '2026-06-04',
            10,
        );
        $service = app(CardStatementMaterializationService::class);

        $service->materialize($workspace, $card, $june, $sixth, $user);

        $july = CreditCardInvoice::query()
            ->where('reference_month', '2026-07-01')
            ->firstOrFail();
        $seventh = $this->statement(
            $workspace,
            $card,
            $july,
            $category,
            '71.90',
            7,
            'Servico Educ*Ntstore - Parcela 7/10',
            '2026-07-04',
            10,
        );

        $service->materialize($workspace, $card, $july, $seventh, $user);

        $this->assertSame(1, FinancialTransaction::query()->count());
        $purchase = FinancialTransaction::query()->sole();
        $this->assertTrue($seventh->refresh()->is_reconciled);
        $this->assertSame(7, $seventh->transactionInstallment->installment_number);
        $this->assertSame($purchase->id, $seventh->transactionInstallment->transaction->id);
    }

    public function test_user_can_merge_installment_fragments_with_different_dates(): void
    {
        [$user, $workspace, $card, $category] = $this->scenario();
        $june = $this->invoice($workspace, $card, '2026-06-01', '2026-06-05', '2026-06-08');
        $july = $this->invoice($workspace, $card, '2026-07-01', '2026-07-05', '2026-07-08');
        $earlier = $this->purchase($workspace, $card, $category, 'Servico Educ*Ntstore - Parcela 6/10', '431.40');
        $earlier->update([
            'transaction_date' => '2026-06-04',
            'competence_date' => '2026-06-04',
        ]);
        $this->installment($earlier, $june, 6, '71.90', 10);
        $projected = $this->installment($earlier, $july, 7, '71.90', 10);
        $later = $this->purchase($workspace, $card, $category, 'Servico Educ*Ntstore - Parcela 7/10', '287.60');
        $later->update([
            'transaction_date' => '2026-07-04',
            'competence_date' => '2026-07-04',
        ]);
        $repeated = $this->installment($later, $july, 7, '71.90', 10);
        $statement = $this->statement(
            $workspace,
            $card,
            $july,
            $category,
            '71.90',
            7,
            'Servico Educ*Ntstore - Parcela 7/10',
            '2026-07-04',
            10,
        );
        $statement->update([
            'transaction_installment_id' => $repeated->id,
            'is_reconciled' => true,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('transactions.edit', $later))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installmentPlan.canonical_id', $earlier->id)
                ->where('installmentPlan.is_canonical', false)
            );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.merge-installment-plan', $later))
            ->assertRedirect(route('transactions.edit', $earlier));

        $this->assertDatabaseMissing('financial_transactions', ['id' => $later->id]);
        $this->assertSame($projected->id, $statement->refresh()->transaction_installment_id);
        $this->assertSame('143.80', $earlier->refresh()->amount);
    }

    /**
     * @return array{User, Workspace, CreditCard, Category}
     */
    private function scenario(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 5,
            'due_day' => 8,
        ]);
        $category = Category::factory()->for($workspace)->create([
            'name' => 'Mercado',
        ]);

        return [$user, $workspace, $card, $category];
    }

    private function invoice(
        Workspace $workspace,
        CreditCard $card,
        string $referenceMonth,
        string $closingDate,
        string $dueDate,
    ): CreditCardInvoice {
        return CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => $referenceMonth,
            'closing_date' => $closingDate,
            'due_date' => $dueDate,
            'calculated_amount' => '0.00',
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Open,
        ]);
    }

    private function statement(
        Workspace $workspace,
        CreditCard $card,
        CreditCardInvoice $invoice,
        Category $category,
        string $amount,
        int $installmentNumber,
        string $description = 'LOJA Parcela 1 de 8',
        string $purchasedOn = '2026-05-13',
        int $totalInstallments = 8,
    ): CardStatementEntry {
        $description = $installmentNumber === 1 && $description === 'LOJA Parcela 1 de 8'
            ? 'LOJA Parcela 1 de 8'
            : ($description === 'LOJA Parcela 1 de 8'
                ? "LOJA Parcela {$installmentNumber} de 8"
                : $description);
        $suffix = $invoice->id.'-'.$installmentNumber;
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'type' => FinancialImportType::CardStatement,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => "fatura-{$suffix}.pdf",
            'file_hash' => hash('sha256', "file-{$suffix}"),
            'deduplication_key' => hash('sha256', "import-{$suffix}-".uniqid()),
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
            'deduplication_key' => hash('sha256', "entry-{$suffix}-".uniqid()),
            'is_reconciled' => false,
            'suggested_category_id' => $category->id,
        ]);
    }

    private function purchase(
        Workspace $workspace,
        CreditCard $card,
        Category $category,
        string $description,
        string $amount,
    ): FinancialTransaction {
        return $workspace->financialTransactions()->create([
            'type' => FinancialTransactionType::Expense,
            'transaction_date' => '2026-05-13',
            'competence_date' => '2026-05-13',
            'description' => $description,
            'amount' => $amount,
            'credit_card_id' => $card->id,
            'category_id' => $category->id,
            'payment_method' => PaymentMethod::CreditCard,
            'status' => FinancialTransactionStatus::Confirmed,
            'origin' => FinancialTransactionOrigin::CardImport,
        ]);
    }

    private function installment(
        FinancialTransaction $purchase,
        CreditCardInvoice $invoice,
        int $number,
        string $amount,
        int $totalInstallments = 8,
    ): TransactionInstallment {
        return $purchase->installments()->create([
            'workspace_id' => $purchase->workspace_id,
            'credit_card_invoice_id' => $invoice->id,
            'installment_number' => $number,
            'total_installments' => $totalInstallments,
            'amount' => $amount,
            'competence_month' => '2026-05-01',
            'due_date' => $invoice->due_date,
            'expected_payment_date' => $invoice->due_date,
            'status' => TransactionInstallmentStatus::Open,
        ]);
    }
}
