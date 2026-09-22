<?php

namespace Tests\Unit;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\BankStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\Workspace;
use App\Services\Reconciliation\InvoicePaymentSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_invoice_by_amount_due_date_and_card_name(): void
    {
        $workspace = Workspace::factory()->create();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank',
            'institution' => 'Nubank',
            'payment_account_id' => $account->id,
        ]);
        $invoice = CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => '2026-09-01',
            'closing_date' => '2026-09-08',
            'due_date' => '2026-09-15',
            'calculated_amount' => '2000.00',
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Open->value,
        ]);
        $entry = $this->bankEntry($workspace, $account, '-2000.00', '2026-09-15', 'PAGAMENTO NUBANK');

        $candidates = app(InvoicePaymentSuggestionService::class)->candidates(
            $entry,
            collect([$invoice->load('creditCard')]),
        );

        $this->assertCount(1, $candidates);
        $this->assertSame($invoice->id, $candidates[0]['invoice_id']);
        $this->assertTrue($candidates[0]['is_suggestion']);
        $this->assertSame('high', $candidates[0]['confidence']);
        $this->assertSame(
            'Possível pagamento da Fatura Nubank Setembro — R$ 2.000,00',
            $candidates[0]['description'],
        );
    }

    public function test_does_not_suggest_invoice_from_unrelated_amount(): void
    {
        $workspace = Workspace::factory()->create();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $card = CreditCard::factory()->for($workspace)->create(['name' => 'Nubank']);
        $invoice = CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => '2026-09-01',
            'closing_date' => '2026-09-08',
            'due_date' => '2026-09-15',
            'calculated_amount' => '2000.00',
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Open->value,
        ]);
        $entry = $this->bankEntry($workspace, $account, '-89.90', '2026-09-15', 'Energia elétrica');

        $candidates = app(InvoicePaymentSuggestionService::class)->candidates(
            $entry,
            collect([$invoice->load('creditCard')]),
        );

        $this->assertSame([], $candidates);
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
            'source_filename' => 'extrato.ofx',
            'file_hash' => hash('sha256', 'file-'.$workspace->id),
            'deduplication_key' => hash('sha256', 'import-'.$workspace->id),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'imported_at' => now(),
        ]);

        return BankStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'financial_account_id' => $account->id,
            'external_id' => 'fit-1',
            'deduplication_key' => hash('sha256', 'entry-'.$workspace->id),
            'occurred_on' => $occurredOn,
            'amount' => $amount,
            'transaction_type' => 'DEBIT',
            'description' => $description,
            'memo' => null,
            'is_reconciled' => false,
        ]);
    }
}
