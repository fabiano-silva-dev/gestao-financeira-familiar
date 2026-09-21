<?php

namespace Tests\Feature;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreditCardPanoramaTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_exposes_total_used_and_available_limits_for_active_cards(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $card = CreditCard::factory()
            ->for($workspace)
            ->create([
                'name' => 'Nubank principal',
                'credit_limit' => '5000.00',
            ]);
        $inactiveCard = CreditCard::factory()
            ->for($workspace)
            ->inactive()
            ->create([
                'name' => 'Cartão antigo',
                'credit_limit' => '1000.00',
            ]);

        $this->createPurchase($workspace, $card, '1200.00');
        $this->createPurchase($workspace, $inactiveCard, '500.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('credit-cards.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credit-cards/index')
                ->where('summary.total_limit', '5000.00')
                ->where('summary.used_limit', '1200.00')
                ->where('summary.available_limit', '3800.00')
                ->where('cards.0.id', $card->id)
                ->where('cards.0.used_limit', '1200.00')
                ->where('cards.0.available_limit', '3800.00')
            );
    }

    public function test_show_displays_card_panorama_invoices_and_transactions(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $card = CreditCard::factory()
            ->for($workspace)
            ->create([
                'name' => 'Mercado Pago Fabiano',
                'credit_limit' => '5000.00',
            ]);

        $currentInvoice = $this->createInvoice(
            $workspace,
            $card,
            '2026-10-01',
            '2026-10-05',
            '2026-10-12',
            '600.00',
        );
        $nextInvoice = $this->createInvoice(
            $workspace,
            $card,
            '2026-11-01',
            '2026-11-05',
            '2026-11-12',
            '600.00',
        );

        $transaction = $this->createPurchase(
            $workspace,
            $card,
            '1200.00',
            [
                [$currentInvoice->id, '600.00', '2026-10-12'],
                [$nextInvoice->id, '600.00', '2026-11-12'],
            ],
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('credit-cards.show', $card))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credit-cards/show')
                ->where('card.id', $card->id)
                ->where('card.used_limit', '1200.00')
                ->where('card.available_limit', '3800.00')
                ->where('currentInvoice.id', $currentInvoice->id)
                ->where('currentInvoice.outstanding_amount', '600.00')
                ->where('nextInvoice.id', $nextInvoice->id)
                ->has('transactions', 1)
                ->where('transactions.0.id', $transaction->id)
                ->where('transactions.0.description', 'Compra do cartão')
                ->where('transactions.0.installment_count', 2)
                ->where('transactions.0.open_installment_count', 2)
            );
    }

    public function test_show_does_not_expose_card_from_another_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherCard = CreditCard::factory()->for($otherWorkspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('credit-cards.show', $otherCard))
            ->assertNotFound();
    }

    /**
     * @param  array<int, array{int, string, string}>|null  $installments
     */
    private function createPurchase(
        Workspace $workspace,
        CreditCard $card,
        string $amount,
        ?array $installments = null,
    ): FinancialTransaction {
        $transaction = $workspace->financialTransactions()->create([
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-15',
            'competence_date' => '2026-09-15',
            'description' => 'Compra do cartão',
            'amount' => $amount,
            'credit_card_id' => $card->id,
            'payment_method' => PaymentMethod::CreditCard->value,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'origin' => FinancialTransactionOrigin::Manual->value,
        ]);

        $items = $installments ?? [[0, $amount, '2026-10-12']];
        $totalInstallments = count($items);

        foreach ($items as $index => [$invoiceId, $installmentAmount, $dueDate]) {
            $transaction->installments()->create([
                'workspace_id' => $workspace->id,
                'credit_card_invoice_id' => $invoiceId === 0 ? null : $invoiceId,
                'installment_number' => $index + 1,
                'total_installments' => $totalInstallments,
                'amount' => $installmentAmount,
                'competence_month' => '2026-09-01',
                'due_date' => $dueDate,
                'status' => TransactionInstallmentStatus::Open->value,
            ]);
        }

        return $transaction;
    }

    private function createInvoice(
        Workspace $workspace,
        CreditCard $card,
        string $referenceMonth,
        string $closingDate,
        string $dueDate,
        string $amount,
    ): CreditCardInvoice {
        return $workspace->creditCardInvoices()->create([
            'credit_card_id' => $card->id,
            'reference_month' => $referenceMonth,
            'closing_date' => $closingDate,
            'due_date' => $dueDate,
            'calculated_amount' => $amount,
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Open->value,
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
