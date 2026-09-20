<?php

namespace Tests\Feature;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Category;
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
                ->where('metrics.current_balance', '1375.00')
                ->where('metrics.income', '500.00')
                ->where('metrics.expenses', '125.00')
                ->where('metrics.projected_balance', '1175.00')
                ->where('metrics.active_accounts', 1)
                ->where('cashFlow.5.income', '500.00')
                ->where('cashFlow.5.expenses', '125.00')
                ->has('categoryExpenses', 1)
                ->where('categoryExpenses.0.name', 'Esportes')
                ->where('categoryExpenses.0.amount', '125.00')
                ->has('upcomingEntries', 1)
                ->where('upcomingEntries.0.description', 'Mensalidade futura')
                ->has('recentEntries', 3)
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
