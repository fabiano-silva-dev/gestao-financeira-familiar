<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountType;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinancialAccountPanoramaTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserve_account_type_can_be_created(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('accounts.store'), [
                'name' => 'Mercado Pago - Cofrinho',
                'institution' => 'Mercado Pago',
                'type' => FinancialAccountType::Reserve->value,
                'opening_balance' => '500.00',
                'opening_balance_date' => '2026-09-01',
            ])
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            FinancialAccountType::Reserve,
            FinancialAccount::query()->sole()->type,
        );
    }

    public function test_index_summarizes_balance_from_active_accounts_only(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '100.00',
            'opening_balance_date' => '2026-09-01',
            'is_active' => true,
        ]);
        FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '-20.00',
            'opening_balance_date' => '2026-09-01',
            'is_active' => true,
        ]);
        FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '999.00',
            'opening_balance_date' => '2026-09-01',
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('accounts/index')
                ->where('summary.total_balance', '80.00')
                ->where('summary.active_accounts', 2)
            );
    }

    public function test_account_panorama_shows_effective_movements_after_opening_balance_date(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
            'opening_balance' => '100.00',
            'opening_balance_date' => '2026-09-01',
        ]);

        $service = app(FinancialEntryService::class);

        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Income,
            '2026-08-30',
            'Movimento anterior',
            '999.00',
        );
        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Income,
            '2026-09-05',
            'Recebimento',
            '150.00',
        );
        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            '2026-09-06',
            'Pagamento',
            '40.00',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.show', ['account' => $account, 'period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('accounts/show')
                ->where('account.id', $account->id)
                ->where('account.current_balance', '210.00')
                ->where('currentPeriod', '2026-09-01')
                ->where('summary.period_opening_balance', '100.00')
                ->where('summary.inflows', '150.00')
                ->where('summary.outflows', '40.00')
                ->where('summary.period_closing_balance', '210.00')
                ->where('summary.movement_count', 2)
                ->has('movements', 2)
                ->where('movements.0.description', 'Recebimento')
                ->where('movements.0.amount', '150.00')
                ->where('movements.0.occurred_on', '2026-09-05')
                ->whereNotNull('movements.0.transaction_id')
                ->where('movements.1.description', 'Pagamento')
                ->where('movements.1.amount', '-40.00')
                ->where('movements.1.occurred_on', '2026-09-06')
            );
    }

    public function test_account_statement_defaults_to_current_month_in_ascending_date_order(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
            'opening_balance' => '100.00',
            'opening_balance_date' => '2026-08-01',
        ]);

        $service = app(FinancialEntryService::class);

        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Income,
            '2026-08-15',
            'Recebimento de agosto',
            '80.00',
        );
        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            '2026-09-03',
            'Conta de luz',
            '30.00',
        );
        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Income,
            '2026-09-10',
            'Freelance',
            '200.00',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('accounts/show')
                ->where('currentPeriod', '2026-09-01')
                ->where('account.current_balance', '350.00')
                ->where('summary.period_opening_balance', '180.00')
                ->where('summary.inflows', '200.00')
                ->where('summary.outflows', '30.00')
                ->where('summary.period_closing_balance', '350.00')
                ->where('summary.movement_count', 2)
                ->has('movements', 2)
                ->where('movements.0.description', 'Conta de luz')
                ->where('movements.1.description', 'Freelance')
            );
    }

    public function test_account_statement_can_open_another_period(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '100.00',
            'opening_balance_date' => '2026-08-01',
        ]);

        $service = app(FinancialEntryService::class);

        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Income,
            '2026-08-15',
            'Recebimento de agosto',
            '80.00',
        );
        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            '2026-09-03',
            'Conta de luz',
            '30.00',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.show', ['account' => $account, 'period' => '2026-08']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('accounts/show')
                ->where('currentPeriod', '2026-08-01')
                ->where('account.current_balance', '150.00')
                ->where('summary.period_opening_balance', '100.00')
                ->where('summary.inflows', '80.00')
                ->where('summary.outflows', '0.00')
                ->where('summary.period_closing_balance', '180.00')
                ->where('summary.movement_count', 1)
                ->has('movements', 1)
                ->where('movements.0.description', 'Recebimento de agosto')
            );
    }

    public function test_account_statement_recalculates_balances_when_the_month_changes(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '0.00',
            'opening_balance_date' => null,
        ]);

        $service = app(FinancialEntryService::class);

        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Income,
            '2026-06-06',
            'Entrada de junho',
            '500.00',
        );
        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            '2026-06-06',
            'Saída de junho',
            '500.00',
        );
        $this->createEntry(
            $service,
            $workspace,
            $account,
            FinancialTransactionType::Income,
            '2026-07-02',
            'Entrada de julho',
            '1196.55',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.show', ['account' => $account, 'period' => '2026-06']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.period_opening_balance', '0.00')
                ->where('summary.inflows', '500.00')
                ->where('summary.outflows', '500.00')
                ->where('summary.period_closing_balance', '0.00')
                ->where('account.current_balance', '1196.55')
            );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.show', ['account' => $account, 'period' => '2026-07']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.period_opening_balance', '0.00')
                ->where('summary.inflows', '1196.55')
                ->where('summary.outflows', '0.00')
                ->where('summary.period_closing_balance', '1196.55')
            );
    }

    public function test_account_panorama_cannot_access_another_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.show', $otherAccount))
            ->assertNotFound();
    }

    private function createEntry(
        FinancialEntryService $service,
        Workspace $workspace,
        FinancialAccount $account,
        FinancialTransactionType $type,
        string $date,
        string $description,
        string $amount,
    ): void {
        $service->create($workspace, [
            'type' => $type->value,
            'transaction_date' => $date,
            'description' => $description,
            'amount' => $amount,
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix->value,
            'payee_name' => null,
            'payment_instructions' => null,
            'due_date' => $date,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);
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
}
