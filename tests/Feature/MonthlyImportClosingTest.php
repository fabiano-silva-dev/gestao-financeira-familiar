<?php

namespace Tests\Feature;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialPeriodClosure;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonthlyImportClosingTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_account_without_import_appears_as_not_imported(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create(['name' => 'Mercado Pago']);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.closing.index', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/closing')
                ->has('accounts', 1)
                ->where('accounts.0.id', $account->id)
                ->where('accounts.0.status', 'not_imported')
                ->where('accounts.0.import_count', 0)
            );
    }

    public function test_active_card_without_statement_appears_as_not_imported(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create(['name' => 'Nubank Fabiano']);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.closing.index', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('cards', 1)
                ->where('cards.0.id', $card->id)
                ->where('cards.0.status', 'not_imported')
            );
    }

    public function test_import_with_pending_entries_appears_as_pending_reconciliation(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $import = $this->bankImport($workspace, $account, $user, '2026-09-01', '2026-09-30');
        $this->bankEntry($workspace, $account, $import, false);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.closing.index', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.status', 'pending_reconciliation')
                ->where('accounts.0.pending_items', 1)
            );
    }

    public function test_fully_reconciled_source_is_not_closed_automatically(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $import = $this->bankImport($workspace, $account, $user, '2026-09-01', '2026-09-30');
        $this->bankEntry($workspace, $account, $import, true);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.closing.index', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.status', 'reconciled')
                ->where('accounts.0.can_close', true)
            );

        $this->assertDatabaseCount('financial_period_closures', 0);
    }

    public function test_closure_is_independent_by_month(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $september = $this->bankImport($workspace, $account, $user, '2026-09-01', '2026-09-30');
        $october = $this->bankImport($workspace, $account, $user, '2026-10-01', '2026-10-31');
        $this->bankEntry($workspace, $account, $september, true, '2026-09-12');
        $this->bankEntry($workspace, $account, $october, true, '2026-10-12');

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('imports.closing.store'), [
            'source_type' => 'account',
            'source_id' => $account->id,
            'period' => '2026-09',
            'action' => 'close',
        ])->assertRedirect(route('imports.closing.index', ['period' => '2026-09']));

        $this->assertDatabaseHas('financial_period_closures', [
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'reference_month' => '2026-09-01',
            'status' => 'closed',
            'closed_by' => $user->id,
        ]);
        $this->assertDatabaseMissing('financial_period_closures', [
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'reference_month' => '2026-10-01',
        ]);

        $request->get(route('imports.closing.index', ['period' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page->where('accounts.0.status', 'closed'));
        $request->get(route('imports.closing.index', ['period' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->where('accounts.0.status', 'reconciled'));
    }

    public function test_closing_cannot_reference_source_from_another_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();
        $otherImport = $this->bankImport($otherWorkspace, $otherAccount, $user, '2026-09-01', '2026-09-30');
        $this->bankEntry($otherWorkspace, $otherAccount, $otherImport, true);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.closing.store'), [
                'source_type' => 'account',
                'source_id' => $otherAccount->id,
                'period' => '2026-09',
                'action' => 'close',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('financial_period_closures', 0);
    }

    public function test_source_from_another_workspace_does_not_appear_in_checklist(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $own = FinancialAccount::factory()->for($workspace)->create(['name' => 'Minha conta']);
        $otherWorkspace = Workspace::factory()->create();
        FinancialAccount::factory()->for($otherWorkspace)->create(['name' => 'Conta externa']);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.closing.index', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('accounts', 1)
                ->where('accounts.0.id', $own->id)
            );
    }

    public function test_partial_statement_period_is_not_treated_as_complete_month(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $import = $this->bankImport($workspace, $account, $user, '2026-09-01', '2026-09-15');
        $this->bankEntry($workspace, $account, $import, true);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.closing.index', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.status', 'imported')
                ->where('accounts.0.coverage_complete', false)
                ->where('accounts.0.can_close', false)
            );
    }

    public function test_multiple_statements_can_complete_monthly_coverage(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $first = $this->bankImport($workspace, $account, $user, '2026-09-01', '2026-09-15');
        $second = $this->bankImport($workspace, $account, $user, '2026-09-16', '2026-09-30');
        $this->bankEntry($workspace, $account, $first, true, '2026-09-05');
        $this->bankEntry($workspace, $account, $second, true, '2026-09-20');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.closing.index', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.status', 'reconciled')
                ->where('accounts.0.coverage_complete', true)
                ->where('accounts.0.import_count', 2)
            );
    }

    public function test_card_statement_is_selected_by_invoice_reference_not_upload_date(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        [$import, $invoice] = $this->cardImport($workspace, $card, $user, '2026-09', '2026-10-05 10:00:00');
        $this->cardEntry($workspace, $card, $import, $invoice, true);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.closing.index', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cards.0.id', $card->id)
                ->where('cards.0.status', 'reconciled')
                ->where('cards.0.reference_month', '2026-09')
            );
    }

    public function test_source_without_import_can_be_confirmed_as_no_movement(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create(['name' => 'Cartão sem uso']);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('imports.closing.store'), [
            'source_type' => 'card',
            'source_id' => $card->id,
            'period' => '2026-09',
            'action' => 'no_movement',
        ])->assertRedirect(route('imports.closing.index', ['period' => '2026-09']));

        $this->assertDatabaseHas('financial_period_closures', [
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => '2026-09-01',
            'status' => 'no_movement',
            'closed_by' => $user->id,
        ]);

        $request->get(route('imports.closing.index', ['period' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cards.0.status', 'no_movement')
                ->where('summary.completed', 1)
                ->where('summary.pending_total', 0)
            );
    }

    public function test_main_filters_work_for_monthly_closing(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $notImported = FinancialAccount::factory()->for($workspace)->create(['name' => 'A Não importada']);
        $reconciled = FinancialAccount::factory()->for($workspace)->create(['name' => 'B Conciliada']);
        $closed = FinancialAccount::factory()->for($workspace)->create(['name' => 'C Fechada']);

        foreach ([$reconciled, $closed] as $account) {
            $import = $this->bankImport($workspace, $account, $user, '2026-09-01', '2026-09-30');
            $this->bankEntry($workspace, $account, $import, true);
        }

        FinancialPeriodClosure::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $closed->id,
            'credit_card_id' => null,
            'reference_month' => '2026-09-01',
            'status' => 'closed',
            'closed_by' => $user->id,
            'closed_at' => now()->addMinute(),
        ]);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->get(route('imports.closing.index', ['period' => '2026-09', 'status' => 'not_imported']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('accounts', 1)
                ->where('accounts.0.id', $notImported->id)
            );
        $request->get(route('imports.closing.index', ['period' => '2026-09', 'status' => 'reconciled']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('accounts', 1)
                ->where('accounts.0.id', $reconciled->id)
            );
        $request->get(route('imports.closing.index', ['period' => '2026-09', 'status' => 'closed']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('accounts', 1)
                ->where('accounts.0.id', $closed->id)
            );
        $request->get(route('imports.closing.index', ['period' => '2026-09', 'status' => 'pending']))
            ->assertInertia(fn (Assert $page) => $page->has('accounts', 2));
    }

    public function test_history_still_works_with_source_and_period_filters(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Sicredi',
            'institution' => 'Sicredi',
        ]);
        $import = $this->bankImport($workspace, $account, $user, '2026-09-01', '2026-09-30');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.index', [
                'account' => $account->id,
                'period' => '2026-09',
                'sort' => 'filename',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/index')
                ->has('imports', 1)
                ->where('imports.0.id', $import->id)
                ->where('imports.0.institution', 'Sicredi')
                ->where('filters.period', '2026-09')
            );
    }

    /** @return array{0: User, 1: Workspace} */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }

    private function bankImport(
        Workspace $workspace,
        FinancialAccount $account,
        User $user,
        string $start,
        string $end,
    ): FinancialImport {
        $token = (string) Str::uuid();

        return FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'created_by' => $user->id,
            'type' => FinancialImportType::Ofx,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => "extrato-{$token}.ofx",
            'stored_path' => null,
            'file_hash' => hash('sha256', "file-{$token}"),
            'deduplication_key' => hash('sha256', "dedupe-{$token}"),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'statement_start_on' => $start,
            'statement_end_on' => $end,
            'external_account_identifier' => null,
            'metadata' => null,
            'error_message' => null,
            'imported_at' => now()->subHour(),
        ]);
    }

    private function bankEntry(
        Workspace $workspace,
        FinancialAccount $account,
        FinancialImport $import,
        bool $reconciled,
        string $date = '2026-09-10',
    ): BankStatementEntry {
        $token = (string) Str::uuid();

        return BankStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'financial_account_id' => $account->id,
            'external_id' => $token,
            'deduplication_key' => hash('sha256', $token),
            'occurred_on' => $date,
            'amount' => '-10.00',
            'transaction_type' => 'DEBIT',
            'description' => 'Movimento de teste',
            'memo' => null,
            'is_reconciled' => $reconciled,
        ]);
    }

    /** @return array{FinancialImport, CreditCardInvoice} */
    private function cardImport(
        Workspace $workspace,
        CreditCard $card,
        User $user,
        string $referenceMonth,
        string $importedAt,
    ): array {
        $reference = CarbonImmutable::parse($referenceMonth.'-01');
        $invoice = CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => $reference->toDateString(),
            'closing_date' => $reference->subDays(5)->toDateString(),
            'due_date' => $reference->addDays(9)->toDateString(),
            'calculated_amount' => '100.00',
            'statement_amount' => '100.00',
            'paid_amount' => '0.00',
            'paid_at' => null,
            'status' => CreditCardInvoiceStatus::Open,
        ]);
        $token = (string) Str::uuid();
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => null,
            'credit_card_id' => $card->id,
            'created_by' => $user->id,
            'type' => FinancialImportType::CardStatement,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => "fatura-{$token}.csv",
            'stored_path' => null,
            'file_hash' => hash('sha256', "file-{$token}"),
            'deduplication_key' => hash('sha256', "dedupe-{$token}"),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'statement_start_on' => $reference->subMonth()->toDateString(),
            'statement_end_on' => $reference->toDateString(),
            'external_account_identifier' => null,
            'metadata' => [
                'reference_month' => $referenceMonth,
                'statement_amount' => '100.00',
                'credit_card_invoice_id' => $invoice->id,
            ],
            'error_message' => null,
            'imported_at' => CarbonImmutable::parse($importedAt),
        ]);

        return [$import, $invoice];
    }

    private function cardEntry(
        Workspace $workspace,
        CreditCard $card,
        FinancialImport $import,
        CreditCardInvoice $invoice,
        bool $reconciled,
    ): CardStatementEntry {
        $token = (string) Str::uuid();

        return CardStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'credit_card_id' => $card->id,
            'credit_card_invoice_id' => $invoice->id,
            'purchased_on' => '2026-08-20',
            'description' => 'Compra de teste',
            'amount' => '100.00',
            'installment_number' => null,
            'total_installments' => null,
            'external_id' => $token,
            'deduplication_key' => hash('sha256', $token),
            'raw_data' => null,
            'is_reconciled' => $reconciled,
        ]);
    }
}
