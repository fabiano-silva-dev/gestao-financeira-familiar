<?php

namespace Tests\Feature;

use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AvailabilityDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_availability_dashboard(): void
    {
        $this->get(route('availability'))
            ->assertRedirect(route('login'));
    }

    public function test_dashboard_separates_cash_overdraft_cards_and_next_month_receivables(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00'));

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        $positiveAccount = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
            'opening_balance' => '1000.00',
            'overdraft_limit' => '500.00',
        ]);
        FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta negativa',
            'opening_balance' => '-200.00',
            'overdraft_limit' => '1000.00',
        ]);
        FinancialAccount::factory()->for($otherWorkspace)->create([
            'opening_balance' => '9999.00',
            'overdraft_limit' => '9999.00',
        ]);

        CreditCard::factory()->for($workspace)->create([
            'name' => 'Cartão vira dia 2',
            'credit_limit' => '1000.00',
            'closing_day' => 2,
            'due_day' => 10,
        ]);
        CreditCard::factory()->for($workspace)->create([
            'name' => 'Cartão vira dia 25',
            'credit_limit' => '500.00',
            'closing_day' => 25,
            'due_day' => 5,
        ]);
        CreditCard::factory()->for($otherWorkspace)->create([
            'credit_limit' => '9999.00',
        ]);

        $workspace->financialTransactions()->create([
            'type' => FinancialTransactionType::Income->value,
            'transaction_date' => '2026-09-29',
            'description' => 'Recebimento de outubro',
            'amount' => '2000.00',
            'financial_account_id' => $positiveAccount->id,
            'due_date' => '2026-10-10',
            'status' => FinancialTransactionStatus::Planned->value,
            'origin' => FinancialTransactionOrigin::Manual->value,
        ]);
        $workspace->financialTransactions()->create([
            'type' => FinancialTransactionType::Income->value,
            'transaction_date' => '2026-09-29',
            'description' => 'Recebimento de setembro',
            'amount' => '100.00',
            'financial_account_id' => $positiveAccount->id,
            'due_date' => '2026-09-30',
            'status' => FinancialTransactionStatus::Planned->value,
            'origin' => FinancialTransactionOrigin::Manual->value,
        ]);
        $otherWorkspace->financialTransactions()->create([
            'type' => FinancialTransactionType::Income->value,
            'transaction_date' => '2026-09-29',
            'description' => 'Outro workspace',
            'amount' => '9999.00',
            'due_date' => '2026-10-10',
            'status' => FinancialTransactionStatus::Planned->value,
            'origin' => FinancialTransactionOrigin::Manual->value,
        ]);
        $installmentIncome = $workspace->financialTransactions()->create([
            'type' => FinancialTransactionType::Income->value,
            'transaction_date' => '2026-09-29',
            'description' => 'Serviços parcelados',
            'amount' => '1500.00',
            'financial_account_id' => $positiveAccount->id,
            'due_date' => '2026-10-15',
            'status' => FinancialTransactionStatus::Planned->value,
            'origin' => FinancialTransactionOrigin::Manual->value,
        ]);
        $installmentIncome->installments()->create([
            'workspace_id' => $workspace->id,
            'installment_number' => 1,
            'total_installments' => 3,
            'amount' => '500.00',
            'competence_month' => '2026-10-01',
            'due_date' => '2026-10-15',
            'expected_payment_date' => '2026-10-15',
            'status' => TransactionInstallmentStatus::Open->value,
        ]);
        $installmentIncome->installments()->create([
            'workspace_id' => $workspace->id,
            'installment_number' => 2,
            'total_installments' => 3,
            'amount' => '500.00',
            'competence_month' => '2026-11-01',
            'due_date' => '2026-11-15',
            'expected_payment_date' => '2026-11-15',
            'status' => TransactionInstallmentStatus::Open->value,
        ]);
        $installmentIncome->installments()->create([
            'workspace_id' => $workspace->id,
            'installment_number' => 3,
            'total_installments' => 3,
            'amount' => '500.00',
            'competence_month' => '2026-10-01',
            'due_date' => '2026-10-05',
            'expected_payment_date' => '2026-10-05',
            'paid_at' => '2026-10-05',
            'status' => TransactionInstallmentStatus::Paid->value,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('availability'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('availability-dashboard')
                ->where('asOf', '2026-09-29')
                ->where('nextMonth', '2026-10-01')
                ->where('summary.account_balance', '800.00')
                ->where('summary.overdraft_limit', '1500.00')
                ->where('summary.overdraft_used', '200.00')
                ->where('summary.overdraft_available', '1300.00')
                ->where('summary.card_limit', '1500.00')
                ->where('summary.card_used', '0.00')
                ->where('summary.card_available', '1500.00')
                ->where('summary.next_month_receivable', '2500.00')
                ->has('accounts', 2)
                ->has('cards', 2)
                ->where('cards.0.name', 'Cartão vira dia 2')
                ->where('cards.0.next_closing_date', '2026-10-02')
                ->where('nextClosingCard.name', 'Cartão vira dia 2')
                ->has('receivables', 2)
                ->where('receivables.0.description', 'Recebimento de outubro')
                ->where('receivables.0.amount', '2000.00')
                ->where('receivables.1.description', 'Serviços parcelados')
                ->where('receivables.1.amount', '500.00')
            );
    }
}
