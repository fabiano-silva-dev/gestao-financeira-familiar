<?php

namespace Tests\Feature;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\CreditCardInvoiceService;
use App\Services\Finance\FinancialEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('payments'))
            ->assertRedirect(route('login'));
    }

    public function test_current_month_is_loaded_by_default(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-22 13:00:00'));

        [$user, $workspace] = $this->userWithWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('payments'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments-dashboard')
                ->where('currentPeriod', '2026-09-01')
                ->where('metrics.paid', '0.00')
                ->where('metrics.payable', '0.00')
                ->where('metrics.received', '0.00')
                ->where('metrics.receivable', '0.00')
                ->where('metrics.projected_balance', '0.00')
                ->has('upcoming', 4)
            );
    }

    public function test_dashboard_summarizes_monthly_cash_commitments_without_duplicating_card_purchases(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-22 13:00:00'));

        [$user, $workspace] = $this->userWithWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Mercado Pago',
            'opening_balance' => '0.00',
        ]);
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create([
            'opening_balance' => '0.00',
        ]);
        $service = app(FinancialEntryService::class);

        $this->createEntry($service, $workspace, $account, [
            'transaction_date' => '2026-08-31',
            'competence_date' => '2026-08-31',
            'description' => 'Conta já paga',
            'amount' => '100.00',
            'due_date' => '2026-08-31',
            'settled_on' => '2026-09-05',
        ]);
        $this->createEntry($service, $workspace, $account, [
            'transaction_date' => '2026-09-01',
            'description' => 'Internet atrasada',
            'amount' => '50.00',
            'status' => FinancialTransactionStatus::Planned->value,
            'due_date' => '2026-09-20',
            'settled_on' => null,
        ]);
        $this->createEntry($service, $workspace, $account, [
            'transaction_date' => '2026-09-01',
            'description' => 'Mensalidade',
            'amount' => '200.00',
            'status' => FinancialTransactionStatus::Planned->value,
            'due_date' => '2026-09-25',
            'settled_on' => null,
        ]);
        $this->createEntry($service, $workspace, $account, [
            'type' => FinancialTransactionType::Income->value,
            'transaction_date' => '2026-08-25',
            'competence_date' => '2026-08-25',
            'description' => 'Pró-labore recebido',
            'amount' => '500.00',
            'due_date' => '2026-10-01',
            'settled_on' => '2026-09-10',
        ]);
        $this->createEntry($service, $workspace, $account, [
            'type' => FinancialTransactionType::Income->value,
            'transaction_date' => '2026-09-01',
            'description' => 'Distribuição prevista',
            'amount' => '300.00',
            'status' => FinancialTransactionStatus::Planned->value,
            'due_date' => '2026-09-28',
            'settled_on' => null,
        ]);

        $contract = $this->createEntry($service, $workspace, $account, [
            'transaction_date' => '2026-07-01',
            'description' => 'Crediário Bellart',
            'amount' => '900.00',
            'status' => FinancialTransactionStatus::Planned->value,
            'due_date' => null,
            'settled_on' => null,
        ]);
        $contract->installments()->create([
            'workspace_id' => $workspace->id,
            'credit_card_invoice_id' => null,
            'installment_number' => 2,
            'total_installments' => 6,
            'amount' => '150.00',
            'competence_month' => '2026-09-01',
            'due_date' => '2026-09-26',
            'expected_payment_date' => '2026-09-26',
            'paid_at' => null,
            'status' => TransactionInstallmentStatus::Open,
        ]);

        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
            'last_four' => '1234',
            'payment_account_id' => $account->id,
            'invoice_payment_method' => PaymentMethod::Boleto,
        ]);
        $invoice = $this->createInvoiceWithPurchase(
            $workspace,
            $card,
            '2026-09-30',
            '400.00',
            CreditCardInvoiceStatus::Open,
        );

        $this->createEntry($service, $otherWorkspace, $otherAccount, [
            'transaction_date' => '2026-09-01',
            'description' => 'Não pode aparecer',
            'amount' => '999.00',
            'status' => FinancialTransactionStatus::Planned->value,
            'due_date' => '2026-09-23',
            'settled_on' => null,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('payments'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments-dashboard')
                ->where('metrics.paid', '100.00')
                ->where('metrics.payable', '800.00')
                ->where('metrics.received', '500.00')
                ->where('metrics.receivable', '300.00')
                ->where('metrics.projected_balance', '-100.00')
                ->has('payable', 4)
                ->where('payable.0.description', 'Internet atrasada')
                ->where('payable.0.is_overdue', true)
                ->where('payable.2.source', 'installment')
                ->where('payable.2.description', 'Crediário Bellart')
                ->where('payable.3.id', 'invoice-'.$invoice->id)
                ->where('payable.3.source', 'invoice')
                ->where('payable.3.description', 'Fatura Nubank Fabiano')
                ->where('payable.3.amount', '400.00')
                ->has('payable.3.children', 1)
                ->where('payable.3.children.0.description', 'Compra do cartão')
                ->has('paid', 1)
                ->where('paid.0.date', '2026-09-05')
                ->has('received', 1)
                ->where('received.0.date', '2026-09-10')
                ->has('receivable', 1)
                ->where('upcoming.0.key', 'overdue')
                ->where('upcoming.0.amount', '50.00')
                ->where('upcoming.2.key', 'next_7_days')
                ->where('upcoming.2.amount', '350.00')
                ->where('upcoming.3.key', 'rest_month')
                ->where('upcoming.3.amount', '400.00')
            );
    }

    public function test_card_payment_is_cash_out_and_does_not_recount_invoice_purchases(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-22 13:00:00'));

        [$user, $workspace] = $this->userWithWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
            'opening_balance' => '0.00',
        ]);
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
            'last_four' => '4321',
            'payment_account_id' => $account->id,
        ]);
        $invoice = $this->createInvoiceWithPurchase(
            $workspace,
            $card,
            '2026-09-20',
            '400.00',
            CreditCardInvoiceStatus::Closed,
        );

        app(CreditCardInvoiceService::class)->pay($invoice, [
            'financial_account_id' => $account->id,
            'paid_on' => '2026-09-20',
            'amount' => '400.00',
            'payment_method' => PaymentMethod::Pix->value,
            'notes' => null,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('payments'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.paid', '400.00')
                ->where('metrics.payable', '0.00')
                ->where('metrics.projected_balance', '-400.00')
                ->has('paid', 1)
                ->where('paid.0.source', 'invoice')
                ->where('paid.0.description', 'Fatura Nubank Fabiano')
                ->where('paid.0.amount', '400.00')
                ->where('paid.0.date', '2026-09-20')
                ->has('paid.0.children', 1)
                ->where('paid.0.children.0.description', 'Compra do cartão')
            );
    }

    public function test_selected_next_month_shows_known_invoices_and_recurring_commitments(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-22 13:00:00'));

        [$user, $workspace] = $this->userWithWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '0.00',
        ]);
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Mercado Pago Fabiano',
            'last_four' => '1111',
            'payment_account_id' => $account->id,
        ]);

        $workspace->financialRecurrences()->create([
            'type' => FinancialTransactionType::Expense,
            'description' => 'Vôlei',
            'amount' => '120.00',
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix,
            'payee_name' => null,
            'payment_instructions' => 'PIX mensal',
            'frequency' => RecurrenceFrequency::Monthly,
            'interval' => 1,
            'starts_on' => '2026-10-05',
            'generation_started_on' => '2026-10-05',
            'ends_on' => null,
            'is_active' => true,
            'notes' => null,
        ]);
        $workspace->financialRecurrences()->create([
            'type' => FinancialTransactionType::Income,
            'description' => 'Pró-labore',
            'amount' => '1000.00',
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::BankTransfer,
            'payee_name' => null,
            'payment_instructions' => null,
            'frequency' => RecurrenceFrequency::Monthly,
            'interval' => 1,
            'starts_on' => '2026-10-15',
            'generation_started_on' => '2026-10-15',
            'ends_on' => null,
            'is_active' => true,
            'notes' => null,
        ]);
        CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => '2026-10-01',
            'closing_date' => '2026-09-30',
            'due_date' => '2026-10-10',
            'calculated_amount' => '350.00',
            'statement_amount' => null,
            'paid_amount' => '0.00',
            'paid_at' => null,
            'status' => CreditCardInvoiceStatus::Open,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('payments', ['period' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentPeriod', '2026-10-01')
                ->where('metrics.paid', '0.00')
                ->where('metrics.payable', '470.00')
                ->where('metrics.received', '0.00')
                ->where('metrics.receivable', '1000.00')
                ->where('metrics.projected_balance', '530.00')
                ->has('payable', 2)
                ->where('payable.0.description', 'Vôlei')
                ->where('payable.1.description', 'Fatura Mercado Pago Fabiano')
                ->has('receivable', 1)
                ->where('receivable.0.description', 'Pró-labore')
            );
    }

    /** @return array{0: User, 1: Workspace} */
    private function userWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }

    /** @param  array<string, mixed>  $overrides */
    private function createEntry(
        FinancialEntryService $service,
        Workspace $workspace,
        FinancialAccount $account,
        array $overrides = [],
    ): FinancialTransaction {
        return $service->create($workspace, [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-01',
            'competence_date' => '2026-09-01',
            'description' => 'Lançamento',
            'amount' => '100.00',
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix->value,
            'payee_name' => null,
            'payment_instructions' => null,
            'due_date' => null,
            'settled_on' => null,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
            ...$overrides,
        ]);
    }

    private function createInvoiceWithPurchase(
        Workspace $workspace,
        CreditCard $card,
        string $dueDate,
        string $amount,
        CreditCardInvoiceStatus $status,
    ): CreditCardInvoice {
        $invoice = CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => substr($dueDate, 0, 7).'-01',
            'closing_date' => CarbonImmutable::parse($dueDate)->subDays(5)->toDateString(),
            'due_date' => $dueDate,
            'calculated_amount' => $amount,
            'statement_amount' => $status === CreditCardInvoiceStatus::Open ? null : $amount,
            'paid_amount' => '0.00',
            'paid_at' => null,
            'status' => $status,
        ]);

        $transaction = $workspace->financialTransactions()->create([
            'type' => FinancialTransactionType::Expense,
            'transaction_date' => CarbonImmutable::parse($dueDate)->subDays(15)->toDateString(),
            'competence_date' => CarbonImmutable::parse($dueDate)->startOfMonth()->toDateString(),
            'description' => 'Compra do cartão',
            'amount' => $amount,
            'financial_account_id' => null,
            'source_account_id' => null,
            'destination_account_id' => null,
            'credit_card_id' => $card->id,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::CreditCard,
            'payee_name' => null,
            'payment_instructions' => null,
            'due_date' => null,
            'settled_on' => null,
            'status' => FinancialTransactionStatus::Confirmed,
            'origin' => FinancialTransactionOrigin::Manual,
            'notes' => null,
        ]);
        $transaction->installments()->create([
            'workspace_id' => $workspace->id,
            'credit_card_invoice_id' => $invoice->id,
            'installment_number' => 1,
            'total_installments' => 1,
            'amount' => $amount,
            'competence_month' => CarbonImmutable::parse($dueDate)->startOfMonth()->toDateString(),
            'due_date' => $dueDate,
            'expected_payment_date' => $dueDate,
            'paid_at' => null,
            'status' => TransactionInstallmentStatus::Open,
        ]);

        return $invoice->refresh();
    }
}
