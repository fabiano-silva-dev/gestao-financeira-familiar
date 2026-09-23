<?php

namespace Tests\Feature;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\BankStatementEntry;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinancialImportIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_reconciliation_and_sorts_history_columns(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $alfa = FinancialAccount::factory()->for($workspace)->create(['name' => 'Alfa']);
        $meio = FinancialAccount::factory()->for($workspace)->create(['name' => 'Meio']);
        $zeta = FinancialAccount::factory()->for($workspace)->create(['name' => 'Zeta']);
        $vazio = FinancialAccount::factory()->for($workspace)->create(['name' => 'Vazio']);

        $reconciled = $this->accountImport($workspace, $alfa, $user, 'a-jul.ofx', '2026-07-01', '2026-07-31', 2);
        $this->bankEntry($workspace, $alfa, $reconciled, true);
        $this->bankEntry($workspace, $alfa, $reconciled, true);

        $partial = $this->accountImport($workspace, $meio, $user, 'm-ago.ofx', '2026-08-01', '2026-08-31', 1);
        $this->bankEntry($workspace, $meio, $partial, true);
        $this->bankEntry($workspace, $meio, $partial, false);

        $pending = $this->accountImport($workspace, $zeta, $user, 'z-set.ofx', '2026-09-01', '2026-09-30', 5);
        $this->bankEntry($workspace, $zeta, $pending, false);
        $this->bankEntry($workspace, $zeta, $pending, false);

        $this->accountImport($workspace, $vazio, $user, 'vazio.ofx', null, null, 0);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->get(route('imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/index')
                ->has('imports', 4)
                ->where('imports', function (mixed $imports): bool {
                    $byName = collect($imports)->keyBy('target_name');

                    return $byName['Alfa']['reconciliation_status'] === 'reconciled'
                        && $byName['Alfa']['resolved_records'] === 2
                        && $byName['Alfa']['statement_records'] === 2
                        && $byName['Meio']['reconciliation_status'] === 'partial'
                        && $byName['Meio']['resolved_records'] === 1
                        && $byName['Zeta']['reconciliation_status'] === 'pending'
                        && $byName['Zeta']['resolved_records'] === 0
                        && $byName['Vazio']['reconciliation_status'] === 'unavailable'
                        && $byName['Vazio']['statement_records'] === 0;
                })
            );

        $request->get(route('imports.index', ['sort' => 'target', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('imports.0.target_name', 'Alfa')
                ->where('imports.1.target_name', 'Meio')
                ->where('imports.2.target_name', 'Vazio')
                ->where('imports.3.target_name', 'Zeta')
            );

        $request->get(route('imports.index', ['sort' => 'start', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('imports.0.source_filename', 'a-jul.ofx')
                ->where('imports.1.source_filename', 'm-ago.ofx')
                ->where('imports.2.source_filename', 'z-set.ofx')
                ->where('imports.3.source_filename', 'vazio.ofx')
            );

        $request->get(route('imports.index', ['sort' => 'reconciliation', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('imports.0.reconciliation_status', 'unavailable')
                ->where('imports.1.reconciliation_status', 'reconciled')
                ->where('imports.2.reconciliation_status', 'partial')
                ->where('imports.3.reconciliation_status', 'pending')
            );

        $request->get(route('imports.index', ['sort' => 'summary', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('imports.0.source_filename', 'vazio.ofx')
                ->where('imports.1.source_filename', 'm-ago.ofx')
                ->where('imports.2.source_filename', 'a-jul.ofx')
                ->where('imports.3.source_filename', 'z-set.ofx')
            );

        $request->get(route('imports.index', ['sort' => 'filename', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('imports.0.source_filename', 'z-set.ofx')
                ->where('imports.3.source_filename', 'a-jul.ofx')
            );
    }

    private function accountImport(
        Workspace $workspace,
        FinancialAccount $account,
        User $user,
        string $filename,
        ?string $start,
        ?string $end,
        int $created,
    ): FinancialImport {
        $key = hash('sha256', implode('|', [$workspace->id, $account->id, $filename, uniqid('', true)]));

        return FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'created_by' => $user->id,
            'type' => FinancialImportType::Ofx,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => $filename,
            'stored_path' => null,
            'file_hash' => $key,
            'deduplication_key' => $key,
            'total_records' => $created,
            'imported_records' => $created,
            'duplicate_records' => 0,
            'statement_start_on' => $start,
            'statement_end_on' => $end,
            'external_account_identifier' => null,
            'metadata' => [
                'source_format' => 'ofx',
                'processing_summary' => [
                    'new_transactions_created' => $created,
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
    ): void {
        $key = hash('sha256', 'entry|'.$import->id.'|'.uniqid('', true));

        BankStatementEntry::query()->create([
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
