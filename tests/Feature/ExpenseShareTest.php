<?php

namespace Tests\Feature;

use App\Enums\AccountMovementType;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\BankStatementEntry;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpenseShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_credits_can_close_an_expense_share_without_creating_income(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta Lidiane',
            'opening_balance' => '0.00',
        ]);
        $expense = $this->createExpense(
            $workspace,
            $account,
            '40.00',
            'Aluguel quadra de vôlei',
        );

        $entries = collect([
            ['Colega 1', 'share-001'],
            ['Colega 2', 'share-002'],
            ['Colega 3', 'share-003'],
        ])->map(
            fn (array $item): BankStatementEntry => $this->bankEntry(
                $workspace,
                $account,
                '10.00',
                '2026-10-04',
                'PIX RECEBIDO '.$item[0],
                $item[1],
            ),
        );

        $session = [CurrentWorkspace::SESSION_KEY => $workspace->id];
        $request = $this->actingAs($user)->withSession($session);

        $request
            ->getJson(route('reconciliation.expense-share-candidates', $entries[0]))
            ->assertOk()
            ->assertJsonPath('candidates.0.transaction_id', $expense->id)
            ->assertJsonPath('candidates.0.is_expense_share', true);

        foreach ($entries as $entry) {
            $request->post(route('reconciliation.expense-share', $entry), [
                'financial_transaction_id' => $expense->id,
                'expected_shared_amount' => '30.00',
            ])->assertSessionHasNoErrors();
        }

        $expense->refresh()->load('expenseShare.receipts');

        $this->assertDatabaseMissing('financial_transactions', [
            'workspace_id' => $workspace->id,
            'type' => FinancialTransactionType::Income->value,
        ]);
        $this->assertSame('30.00', $expense->expenseShare?->expected_amount);
        $this->assertCount(3, $expense->expenseShare?->receipts ?? []);
        $this->assertDatabaseCount('expense_share_receipts', 3);
        $this->assertDatabaseHas('account_movements', [
            'workspace_id' => $workspace->id,
            'type' => AccountMovementType::SharedExpenseReceipt->value,
            'amount' => '10.00',
        ]);

        foreach ($entries as $entry) {
            $this->assertTrue($entry->fresh()->is_reconciled);
        }

        $request
            ->get(route('transactions.edit', $expense))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.original_amount', '40.00')
                ->where('entry.expected_shared_amount', '30.00')
                ->where('entry.shared_amount', '30.00')
                ->where('entry.remaining_shared_amount', '0.00')
                ->where('entry.share_status', 'closed')
                ->where('entry.share_status_label', 'Rateio fechado')
                ->where('entry.net_amount', '10.00')
            );

        $request
            ->get(route('dashboard', ['period' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.expenses', '10.00')
                ->where('metrics.income', '0.00')
                ->where('metrics.current_balance', '-10.00')
            );
    }

    private function createExpense(
        Workspace $workspace,
        FinancialAccount $account,
        string $amount,
        string $description,
    ): FinancialTransaction {
        return app(FinancialEntryService::class)->create($workspace, [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-10-04',
            'competence_date' => '2026-10-04',
            'description' => $description,
            'amount' => $amount,
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix->value,
            'payee_name' => 'Quadra',
            'payment_instructions' => null,
            'due_date' => null,
            'settled_on' => '2026-10-04',
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);
    }

    private function bankEntry(
        Workspace $workspace,
        FinancialAccount $account,
        string $amount,
        string $occurredOn,
        string $description,
        string $externalId,
    ): BankStatementEntry {
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'type' => FinancialImportType::Ofx,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => $externalId.'.ofx',
            'file_hash' => hash('sha256', 'file-'.$externalId),
            'deduplication_key' => hash('sha256', 'import-'.$externalId),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'imported_at' => now(),
        ]);

        return BankStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'financial_account_id' => $account->id,
            'external_id' => $externalId,
            'deduplication_key' => hash('sha256', 'entry-'.$externalId),
            'occurred_on' => $occurredOn,
            'amount' => $amount,
            'transaction_type' => 'CREDIT',
            'description' => $description,
            'memo' => null,
            'is_reconciled' => false,
        ]);
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
