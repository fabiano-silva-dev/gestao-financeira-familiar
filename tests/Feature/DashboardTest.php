<?php

namespace Tests\Feature;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\RecurrenceFrequency;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('Gestão Financeira Familiar')
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('metrics.current_balance', '0.00')
                ->where('metrics.projected_balance', '0.00')
                ->has('cashFlow', 6)
                ->has('categoryExpenses', 0)
                ->has('upcomingEntries', 0)
                ->has('recentEntries', 0)
            );
    }

    public function test_dashboard_summarizes_only_the_current_workspace(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        FinancialAccount::factory()->for($otherWorkspace)->create([
            'opening_balance' => '9999.00',
        ]);
        $category = Category::factory()->for($workspace)->create([
            'name' => 'Esportes',
        ]);
        $service = app(FinancialEntryService::class);

        $this->createEntry($service, $workspace, $account, [
            'type' => FinancialTransactionType::Income->value,
            'transaction_date' => '2026-09-05',
            'description' => 'Receita mensal',
            'amount' => '500.00',
        ]);
        $this->createEntry($service, $workspace, $account, [
            'transaction_date' => '2026-09-10',
            'description' => 'Vôlei e handebol',
            'amount' => '125.00',
            'category_id' => $category->id,
        ]);
        $this->createEntry($service, $workspace, $account, [
            'transaction_date' => '2026-09-20',
            'description' => 'Mensalidade futura',
            'amount' => '200.00',
            'status' => FinancialTransactionStatus::Planned->value,
            'due_date' => '2026-09-25',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('currentPeriod', '2026-09-01')
                ->where('metrics.current_balance', '1375.00')
                ->where('metrics.income', '500.00')
                ->where('metrics.expenses', '125.00')
                ->where('metrics.projected_balance', '1175.00')
                ->where('metrics.active_accounts', 1)
                ->where('cashFlow.5.month', '2026-09-01')
                ->where('cashFlow.5.income', '500.00')
                ->where('cashFlow.5.expenses', '325.00')
                ->has('categoryExpenses', 1)
                ->where('categoryExpenses.0.id', $category->id)
                ->where('categoryExpenses.0.name', 'Esportes')
                ->where('categoryExpenses.0.amount', '125.00')
                ->has('upcomingEntries', 1)
                ->where('upcomingEntries.0.description', 'Mensalidade futura')
                ->has('recentEntries', 2)
                ->where('recentEntries.0.status_label', 'Pago')
            );
    }

    public function test_dashboard_filters_monthly_metrics_by_selected_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $service = app(FinancialEntryService::class);

        $this->createEntry($service, $workspace, $account, [
            'type' => FinancialTransactionType::Income->value,
            'transaction_date' => '2026-08-12',
            'description' => 'Receita de agosto',
            'amount' => '400.00',
        ]);
        $this->createEntry($service, $workspace, $account, [
            'transaction_date' => '2026-09-10',
            'description' => 'Despesa de setembro',
            'amount' => '125.00',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('dashboard', ['period' => '2026-08']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('currentPeriod', '2026-08-01')
                ->where('metrics.income', '400.00')
                ->where('metrics.expenses', '0.00')
                ->where('cashFlow.5.month', '2026-08-01')
                ->where('cashFlow.5.income', '400.00')
                ->where('cashFlow.5.expenses', '0.00')
            );
    }

    public function test_dashboard_ignores_invalid_period_and_uses_current_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('dashboard', ['period' => 'mês-inválido']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('currentPeriod', '2026-09-01')
            );
    }

    public function test_dashboard_lists_all_expense_categories_for_the_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);
        $account = FinancialAccount::factory()->for($workspace)->create();
        $service = app(FinancialEntryService::class);
        $amounts = [
            'Moradia' => '300.00',
            'Alimentação' => '250.00',
            'Transporte' => '200.00',
            'Saúde' => '150.00',
            'Educação' => '100.00',
            'Lazer' => '50.00',
        ];

        foreach ($amounts as $name => $amount) {
            $category = Category::factory()->for($workspace)->create([
                'name' => $name,
            ]);
            $this->createEntry($service, $workspace, $account, [
                'transaction_date' => '2026-09-10',
                'description' => $name,
                'amount' => $amount,
                'category_id' => $category->id,
            ]);
        }

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('categoryExpenses', 6)
                ->where('categoryExpenses.0.name', 'Moradia')
                ->where('categoryExpenses.0.amount', '300.00')
                ->where('categoryExpenses.5.name', 'Lazer')
                ->where('categoryExpenses.5.amount', '50.00')
            );
    }

    public function test_dashboard_groups_subcategory_expenses_under_the_parent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);
        $account = FinancialAccount::factory()->for($workspace)->create();
        $parent = Category::factory()->for($workspace)->create([
            'name' => 'Alimentação',
        ]);
        $child = Category::factory()->for($workspace)->create([
            'name' => 'Restaurante',
            'parent_id' => $parent->id,
        ]);
        $service = app(FinancialEntryService::class);

        $this->createEntry($service, $workspace, $account, [
            'transaction_date' => '2026-09-10',
            'description' => 'Jantar',
            'amount' => '80.00',
            'category_id' => $child->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('categoryExpenses', 1)
                ->where('categoryExpenses.0.id', $parent->id)
                ->where('categoryExpenses.0.name', 'Alimentação')
                ->where('categoryExpenses.0.amount', '80.00')
            );
    }

    public function test_dashboard_generates_recurrence_commitments_in_upcoming_entries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);
        $account = FinancialAccount::factory()->for($workspace)->create();

        $workspace->financialRecurrences()->create([
            'type' => FinancialTransactionType::Expense,
            'description' => 'Aluguel — Maiquel Oliveira Imóveis',
            'amount' => '2177.34',
            'financial_account_id' => $account->id,
            'payment_method' => PaymentMethod::Boleto,
            'payee_name' => 'Maiquel Oliveira Imoveis',
            'frequency' => RecurrenceFrequency::Monthly,
            'interval' => 1,
            'starts_on' => '2026-06-08',
            'generation_started_on' => '2026-09-21',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('upcomingEntries', 1)
                ->where('upcomingEntries.0.source', 'transaction')
                ->where(
                    'upcomingEntries.0.description',
                    'Aluguel — Maiquel Oliveira Imóveis',
                )
                ->where('upcomingEntries.0.date', '2026-10-08')
                ->where('upcomingEntries.0.amount', '2177.34')
            );
    }

    public function test_dashboard_includes_unpaid_invoices_in_upcoming_entries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
            'last_four' => '0000',
        ]);
        CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => '2026-10-01',
            'closing_date' => '2026-09-25',
            'due_date' => '2026-10-13',
            'calculated_amount' => '165.53',
            'statement_amount' => null,
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Open,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('upcomingEntries', 1)
                ->where('upcomingEntries.0.source', 'invoice')
                ->where('upcomingEntries.0.description', 'Fatura Nubank Fabiano')
                ->where('upcomingEntries.0.date', '2026-10-13')
                ->where('upcomingEntries.0.amount', '165.53')
            );
    }

    public function test_dashboard_shows_overdue_commitments_and_keeps_them_out_of_recent_entries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $service = app(FinancialEntryService::class);

        $this->createEntry($service, $workspace, $account, [
            'transaction_date' => '2026-09-10',
            'description' => 'Aluguel atrasado',
            'amount' => '200.00',
            'status' => FinancialTransactionStatus::Planned->value,
            'due_date' => '2026-09-10',
            'settled_on' => null,
        ]);
        $this->createEntry($service, $workspace, $account, [
            'transaction_date' => '2026-09-18',
            'description' => 'Mercado pago',
            'amount' => '80.00',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('metrics.current_balance', '920.00')
                ->where('metrics.expenses', '80.00')
                ->where('cashFlow.5.expenses', '280.00')
                ->has('upcomingEntries', 1)
                ->where('upcomingEntries.0.description', 'Aluguel atrasado')
                ->where('upcomingEntries.0.status_label', 'Vencido')
                ->where('upcomingEntries.0.is_overdue', true)
                ->has('recentEntries', 1)
                ->where('recentEntries.0.description', 'Mercado pago')
                ->where('recentEntries.0.status_label', 'Pago')
            );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEntry(
        FinancialEntryService $service,
        Workspace $workspace,
        FinancialAccount $account,
        array $overrides = [],
    ): void {
        $service->create($workspace, [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-20',
            'description' => 'Despesa',
            'amount' => '100.00',
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix->value,
            'payee_name' => null,
            'payment_instructions' => null,
            'due_date' => null,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
            ...$overrides,
        ]);
    }
}
