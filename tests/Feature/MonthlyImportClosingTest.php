<?php

namespace Tests\Feature;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\BankStatementEntry;
use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonthlyImportClosingTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_account_without_import_is_listed_as_not_imported(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        FinancialAccount::factory()->for($workspace)->create(['name' => 'Mercado Pago']);

        $this->page($user, $workspace, '2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/monthly-closing')
                ->has('accounts', 1)
                ->where('accounts.0.name', 'Mercado Pago')
                ->where('accounts.0.status', 'not_imported')
                ->where('summary.not_imported', 1)
            );
    }

    public function test_active_card_without_invoice_is_listed_as_not_imported(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        CreditCard::factory()->for($workspace)->create(['name' => 'Nubank Fabiano']);

        $this->page($user, $workspace, '2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->has('cards', 1)
                ->where('cards.0.name', 'Nubank Fabiano')
                ->where('cards.0.status', 'not_imported')
            );
    }

    public function test_import_with_pending_entries_is_pending_reconciliation(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $import = $this->accountImport($workspace, $account, $user, '2026-09-01', '2026-09-30');
        $this->bankEntry($workspace, $account, $import, false);

        $this->page($user, $workspace, '2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.status', 'pending_reconciliation')
                ->where('accounts.0.pending_items', 1)
                ->where('summary.pending_reconciliation', 1)
            );
    }

    public function test_fully_reconciled_source_is_not_closed_automatically(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $import = $this->accountImport($workspace, $account, $user, '2026-09-01', '2026-09-30');
        $this->bankEntry($workspace, $account, $import, true);

        $this->page($user, $workspace, '2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.status', 'reconciled')
                ->where('accounts.0.closure', null)
                ->where('summary.completed', 0)
            );

        $this->assertDatabaseCount('import_period_closures', 0);
    }

    public function test_closure_is_independent_for_each_month(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post("/importacoes/fechamento-mensal/account/{$account->id}/fechar", [
                'period' => '2026-09',
            ])
            ->assertRedirect('/importacoes/fechamento-mensal?period=2026-09');

        $this->assertDatabaseHas('import_period_closures', [
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'period_month' => '2026-09-01',
            'status' => 'closed',
            'closed_by' => $user->id,
        ]);

        $this->page($user, $workspace, '2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.status', 'not_imported')
                ->where('accounts.0.closure', null)
            );
    }

    public function test_closing_source_from_another_workspace_is_rejected(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post("/importacoes/fechamento-mensal/account/{$otherAccount->id}/fechar", [
                'period' => '2026-09',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('import_period_closures', 0);
    }

    public function test_source_from_another_workspace_does_not_appear(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        FinancialAccount::factory()->for($workspace)->create(['name' => 'Minha conta']);
        $otherWorkspace = Workspace::factory()->create();
        FinancialAccount::factory()->for($otherWorkspace)->create(['name' => 'Conta externa']);
        CreditCard::factory()->for($otherWorkspace)->create(['name' => 'Cartão externo']);

        $this->page($user, $workspace, '2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->has('accounts', 1)
                ->where('accounts.0.name', 'Minha conta')
                ->has('cards', 0)
            );
    }

    public function test_partial_statement_does_not_cover_full_month(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $import = $this->accountImport($workspace, $account, $user, '2026-09-01', '2026-09-15');
        $this->bankEntry($workspace, $account, $import, true);

        $this->page($user, $workspace, '2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.period_complete', false)
                ->where('accounts.0.period_start', '2026-09-01')
                ->where('accounts.0.period_end', '2026-09-15')
                ->where('summary.incomplete_period', 1)
            );
    }

    public function test_main_status_filters_work(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        FinancialAccount::factory()->for($workspace)->create(['name' => 'Sem extrato']);
        $reconciled = FinancialAccount::factory()->for($workspace)->create(['name' => 'Conciliada']);
        $import = $this->accountImport($workspace, $reconciled, $user, '2026-09-01', '2026-09-30');
        $this->bankEntry($workspace, $reconciled, $import, true);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get('/importacoes/fechamento-mensal?period=2026-09&view=not_imported')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'not_imported')
                ->has('accounts', 1)
                ->where('accounts.0.name', 'Sem extrato')
                ->where('counts.not_imported', 1)
                ->where('counts.reconciled', 1)
            );
    }

    public function test_import_history_continues_working_with_filters_and_summary(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Sicredi',
            'institution' => 'Sicredi',
        ]);
        $import = $this->accountImport($workspace, $account, $user, '2026-09-01', '2026-09-30');
        $this->bankEntry($workspace, $account, $import, true);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get("/importacoes/historico?account={$account->id}&period=2026-09")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/history')
                ->has('imports', 1)
                ->where('imports.0.source_filename', 'extrato.ofx')
                ->where('imports.0.institution', 'Sicredi')
                ->where('imports.0.resolved_records', 1)
                ->where('imports.0.pending_records', 0)
                ->where('filters.account', $account->id)
                ->where('filters.period', '2026-09')
            );
    }

    public function test_closed_source_can_be_reopened_with_audit_fields(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post("/importacoes/fechamento-mensal/account/{$account->id}/fechar", [
                'period' => '2026-09',
            ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->delete("/importacoes/fechamento-mensal/account/{$account->id}/reabrir", [
                'period' => '2026-09',
            ])
            ->assertRedirect('/importacoes/fechamento-mensal?period=2026-09');

        $this->assertDatabaseHas('import_period_closures', [
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'period_month' => '2026-09-01',
            'status' => 'open',
            'closed_by' => $user->id,
            'reopened_by' => $user->id,
        ]);
    }

    private function page(User $user, Workspace $workspace, string $period)
    {
        return $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get('/importacoes/fechamento-mensal?period='.$period)
            ->assertOk();
    }

    private function accountImport(
        Workspace $workspace,
        FinancialAccount $account,
        User $user,
        string $start,
        string $end,
    ): FinancialImport {
        $key = hash('sha256', implode('|', [$workspace->id, $account->id, $start, $end, uniqid('', true)]));

        return FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'created_by' => $user->id,
            'type' => FinancialImportType::Ofx,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => 'extrato.ofx',
            'stored_path' => null,
            'file_hash' => $key,
            'deduplication_key' => $key,
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'statement_start_on' => $start,
            'statement_end_on' => $end,
            'external_account_identifier' => null,
            'metadata' => [
                'source_format' => 'ofx',
                'processing_summary' => [
                    'automatically_reconciled' => 1,
                    'remaining_exceptions' => 0,
                ],
            ],
            'error_message' => null,
            'imported_at' => now(),
        ]);
    }

    private function bankEntry(
        Workspace $workspace,
        FinancialAccount $account,
        FinancialImport $import,
        bool $reconciled,
    ): BankStatementEntry {
        $key = hash('sha256', 'entry|'.$import->id.'|'.uniqid('', true));

        return BankStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'financial_account_id' => $account->id,
            'external_id' => null,
            'deduplication_key' => $key,
            'occurred_on' => '2026-09-10',
            'amount' => '-10.00',
            'transaction_type' => 'DEBIT',
            'description' => 'Movimento teste',
            'memo' => null,
            'is_reconciled' => $reconciled,
            'is_ignored' => false,
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
