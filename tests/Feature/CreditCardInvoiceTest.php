<?php

namespace Tests\Feature;

use App\Enums\AccountMovementType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CardStatementEntry;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditCardInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_invoice_can_be_created_without_creating_expense_or_cash_movement(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 25,
            'due_day' => 5,
        ]);

        $response = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-card-invoices.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'due_date' => '2026-10-05',
                'statement_amount' => '389.90',
            ]);

        $invoice = CreditCardInvoice::query()->sole();

        $response
            ->assertRedirect(route('credit-card-invoices.show', $invoice))
            ->assertSessionHasNoErrors();

        $this->assertSame($workspace->id, $invoice->workspace_id);
        $this->assertSame($card->id, $invoice->credit_card_id);
        $this->assertSame('2026-10-01', $invoice->reference_month->toDateString());
        $this->assertSame('2026-09-25', $invoice->closing_date->toDateString());
        $this->assertSame('2026-10-05', $invoice->due_date->toDateString());
        $this->assertSame('0.00', $invoice->calculated_amount);
        $this->assertSame('389.90', $invoice->statement_amount);
        $this->assertSame(CreditCardInvoiceStatus::Closed, $invoice->status);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('transaction_installments', 0);
        $this->assertDatabaseCount('account_movements', 0);
    }

    public function test_manual_invoice_purchases_use_card_statement_materialization_without_cash_duplication(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 25,
            'due_day' => 5,
        ]);
        $category = Category::factory()->for($workspace)->create();

        $response = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-card-invoices.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'due_date' => '2026-10-05',
                'statement_amount' => '189.90',
                'purchases' => [[
                    'purchased_on' => '2026-09-18',
                    'description' => 'Mercado teste',
                    'amount' => '189.90',
                    'installment_number' => 1,
                    'total_installments' => 1,
                    'category_id' => $category->id,
                ]],
            ]);

        $invoice = CreditCardInvoice::query()->sole();
        $entry = CardStatementEntry::query()->sole();
        $transaction = FinancialTransaction::query()->sole();

        $response
            ->assertRedirect(route('credit-card-invoices.show', $invoice))
            ->assertSessionHasNoErrors();

        $this->assertSame(CreditCardInvoiceStatus::Open, $invoice->status);
        $this->assertSame('189.90', $invoice->statement_amount);
        $this->assertSame('189.90', $invoice->calculated_amount);
        $this->assertNull($entry->financial_import_id);
        $this->assertTrue($entry->is_reconciled);
        $this->assertNotNull($entry->transaction_installment_id);
        $this->assertSame(FinancialTransactionOrigin::Manual, $transaction->origin);
        $this->assertSame($category->id, $transaction->category_id);
        $this->assertSame('189.90', $transaction->amount);
        $this->assertDatabaseCount('transaction_installments', 1);
        $this->assertDatabaseCount('account_movements', 0);
    }

    public function test_manual_invoice_reuses_open_invoice_and_reconciles_existing_purchase(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 5,
            'due_day' => 12,
        ]);
        $category = Category::factory()->for($workspace)->create();
        $purchase = $this->createCardPurchase($user, $workspace, $card, '100.00');
        $existingInvoice = $purchase->installments()->sole()->invoice;

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-card-invoices.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'due_date' => '2026-10-12',
                'statement_amount' => '100.00',
                'purchases' => [[
                    'purchased_on' => '2026-09-20',
                    'description' => 'Compra no cartão',
                    'amount' => '100.00',
                    'installment_number' => 1,
                    'total_installments' => 1,
                    'category_id' => $category->id,
                ]],
            ])
            ->assertRedirect(route('credit-card-invoices.show', $existingInvoice))
            ->assertSessionHasNoErrors();

        $entry = CardStatementEntry::query()->sole();

        $this->assertDatabaseCount('credit_card_invoices', 1);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('transaction_installments', 1);
        $this->assertTrue($entry->is_reconciled);
        $this->assertSame(
            $purchase->installments()->sole()->id,
            $entry->transaction_installment_id,
        );
        $this->assertSame($category->id, $purchase->fresh()->category_id);
        $this->assertSame('100.00', $existingInvoice->fresh()->statement_amount);
    }

    public function test_manual_invoice_keeps_unclassified_purchase_pending_until_confirmation(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-card-invoices.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'due_date' => '2026-10-12',
                'statement_amount' => '100.00',
                'purchases' => [[
                    'purchased_on' => '2026-09-20',
                    'description' => 'Compra sem classificação conhecida',
                    'amount' => '100.00',
                    'installment_number' => 1,
                    'total_installments' => 1,
                    'category_id' => null,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $invoice = CreditCardInvoice::query()->sole();
        $entry = CardStatementEntry::query()->sole();

        $this->assertSame(CreditCardInvoiceStatus::Open, $invoice->status);
        $this->assertSame('0.00', $invoice->calculated_amount);
        $this->assertFalse($entry->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 0);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('credit-card-invoices.close', $invoice), [
                'statement_amount' => '100.00',
            ])
            ->assertSessionHasErrors('statement_amount');

        $this->assertSame(
            CreditCardInvoiceStatus::Open,
            $invoice->fresh()->status,
        );
    }

    public function test_manual_invoice_rejects_duplicate_card_and_reference_month(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);
        $data = [
            'credit_card_id' => $card->id,
            'reference_month' => '2026-10',
            'due_date' => '2026-10-12',
            'statement_amount' => '100.00',
        ];

        $request->post(route('credit-card-invoices.store'), $data)
            ->assertSessionHasNoErrors();

        $request->post(route('credit-card-invoices.store'), $data)
            ->assertSessionHasErrors('reference_month');

        $this->assertDatabaseCount('credit_card_invoices', 1);
    }

    public function test_manual_invoice_rejects_card_from_another_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $otherCard = CreditCard::factory()->for($otherWorkspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-card-invoices.store'), [
                'credit_card_id' => $otherCard->id,
                'reference_month' => '2026-10',
                'due_date' => '2026-10-12',
                'statement_amount' => '100.00',
            ])
            ->assertSessionHasErrors('credit_card_id');

        $this->assertDatabaseCount('credit_card_invoices', 0);
    }

    public function test_card_purchase_preserves_total_and_creates_linked_installments_and_invoices(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 5,
            'due_day' => 12,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                ...$this->cardPurchaseData($card),
                'transaction_date' => '2026-09-20',
                'amount' => '300.00',
                'installment_count' => 3,
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $purchase = FinancialTransaction::query()->sole();
        $installments = $purchase->installments()->orderBy('installment_number')->get();
        $invoices = CreditCardInvoice::query()->orderBy('due_date')->get();

        $this->assertSame('300.00', $purchase->amount);
        $this->assertCount(3, $installments);
        $this->assertSame(['100.00', '100.00', '100.00'], $installments->pluck('amount')->all());
        $this->assertSame(
            ['2026-09-01', '2026-10-01', '2026-11-01'],
            $installments->map(fn ($installment) => $installment->competence_month->toDateString())->all(),
        );
        $this->assertSame(
            ['2026-10-12', '2026-11-12', '2026-12-12'],
            $installments->map(fn ($installment) => $installment->due_date->toDateString())->all(),
        );
        $this->assertCount(3, $invoices);
        $this->assertSame(
            ['2026-10-01', '2026-11-01', '2026-12-01'],
            $invoices->map(fn ($invoice) => $invoice->reference_month->toDateString())->all(),
        );
        $this->assertSame(['100.00', '100.00', '100.00'], $invoices->pluck('calculated_amount')->all());
        $this->assertDatabaseCount('account_movements', 0);
    }

    public function test_installment_rounding_preserves_exact_purchase_total(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 5,
            'due_day' => 12,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                ...$this->cardPurchaseData($card),
                'amount' => '100.00',
                'installment_count' => 3,
            ])
            ->assertSessionHasNoErrors();

        $amounts = FinancialTransaction::query()
            ->sole()
            ->installments()
            ->orderBy('installment_number')
            ->pluck('amount')
            ->all();

        $this->assertSame(['33.34', '33.33', '33.33'], $amounts);
    }

    public function test_open_invoice_must_be_closed_before_payment(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $card = CreditCard::factory()->for($workspace)->create([
            'payment_account_id' => $account->id,
        ]);
        $purchase = $this->createCardPurchase($user, $workspace, $card, '100.00');
        $invoice = $purchase->installments()->sole()->invoice;

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-card-invoices.pay', $invoice), [
                'financial_account_id' => $account->id,
                'paid_on' => '2026-10-10',
                'amount' => '100.00',
                'payment_method' => PaymentMethod::Pix->value,
            ])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('credit_card_invoice_payments', 0);
        $this->assertDatabaseCount('account_movements', 0);
    }

    public function test_paying_invoice_changes_cash_once_without_creating_new_expense(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 5,
            'due_day' => 12,
            'payment_account_id' => $account->id,
            'invoice_payment_method' => PaymentMethod::Boleto->value,
        ]);
        $purchase = $this->createCardPurchase($user, $workspace, $card, '250.00');
        $invoice = $purchase->installments()->sole()->invoice;
        $this->closeInvoice($user, $workspace, $invoice);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-card-invoices.pay', $invoice), [
                'financial_account_id' => $account->id,
                'paid_on' => '2026-10-10',
                'amount' => '250.00',
                'payment_method' => PaymentMethod::Boleto->value,
                'notes' => null,
            ])
            ->assertRedirect(route('credit-card-invoices.show', $invoice))
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        $movement = $invoice->payments()->sole()->movement()->sole();

        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertSame(FinancialTransactionType::Expense, $purchase->fresh()->type);
        $this->assertSame(CreditCardInvoiceStatus::Paid, $invoice->status);
        $this->assertSame('250.00', $invoice->paid_amount);
        $this->assertSame(AccountMovementType::CardPayment, $movement->type);
        $this->assertNull($movement->financial_transaction_id);
        $this->assertSame('-250.00', $movement->amount);
        $this->assertSame(
            TransactionInstallmentStatus::Paid,
            $purchase->installments()->sole()->fresh()->status,
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('accounts.0.current_balance', '750.00'));
    }

    public function test_partial_payment_keeps_remaining_invoice_balance(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $card = CreditCard::factory()->for($workspace)->create([
            'payment_account_id' => $account->id,
            'invoice_payment_method' => PaymentMethod::Pix->value,
        ]);
        $purchase = $this->createCardPurchase($user, $workspace, $card, '400.00');
        $invoice = $purchase->installments()->sole()->invoice;
        $this->closeInvoice($user, $workspace, $invoice);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-card-invoices.pay', $invoice), [
                'financial_account_id' => $account->id,
                'paid_on' => '2026-10-10',
                'amount' => '150.00',
                'payment_method' => PaymentMethod::Pix->value,
                'notes' => null,
            ])
            ->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertSame(CreditCardInvoiceStatus::Partial, $invoice->status);
        $this->assertSame('150.00', $invoice->paid_amount);
        $this->assertNull($invoice->paid_at);
        $this->assertSame(
            TransactionInstallmentStatus::Open,
            $purchase->installments()->sole()->fresh()->status,
        );
    }

    public function test_second_payment_completes_a_partially_paid_invoice(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $card = CreditCard::factory()->for($workspace)->create([
            'payment_account_id' => $account->id,
            'invoice_payment_method' => PaymentMethod::Pix->value,
        ]);
        $purchase = $this->createCardPurchase($user, $workspace, $card, '400.00');
        $invoice = $purchase->installments()->sole()->invoice;
        $this->closeInvoice($user, $workspace, $invoice);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('credit-card-invoices.pay', $invoice), [
            'financial_account_id' => $account->id,
            'paid_on' => '2026-10-10',
            'amount' => '150.00',
            'payment_method' => PaymentMethod::Pix->value,
            'notes' => null,
        ])->assertSessionHasNoErrors();

        $request->post(route('credit-card-invoices.pay', $invoice), [
            'financial_account_id' => $account->id,
            'paid_on' => '2026-10-12',
            'amount' => '250.00',
            'payment_method' => PaymentMethod::Pix->value,
            'notes' => null,
        ])->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertSame(CreditCardInvoiceStatus::Paid, $invoice->status);
        $this->assertSame('400.00', $invoice->paid_amount);
        $this->assertSame('2026-10-12', $invoice->paid_at?->toDateString());
        $this->assertDatabaseCount('credit_card_invoice_payments', 2);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertSame(
            TransactionInstallmentStatus::Paid,
            $purchase->installments()->sole()->fresh()->status,
        );
    }

    public function test_invoice_from_another_workspace_cannot_be_viewed_or_paid(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();
        $otherCard = CreditCard::factory()->for($otherWorkspace)->create();
        $otherInvoice = CreditCardInvoice::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'credit_card_id' => $otherCard->id,
            'reference_month' => '2026-10-01',
            'closing_date' => '2026-10-05',
            'due_date' => '2026-10-12',
            'calculated_amount' => '100.00',
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Open->value,
        ]);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->get(route('credit-card-invoices.show', $otherInvoice))
            ->assertNotFound();

        $request->post(route('credit-card-invoices.pay', $otherInvoice), [
            'financial_account_id' => $otherAccount->id,
            'paid_on' => '2026-10-10',
            'amount' => '100.00',
            'payment_method' => PaymentMethod::Pix->value,
        ])->assertSessionHasErrors('financial_account_id');
    }

    private function closeInvoice(
        User $user,
        Workspace $workspace,
        CreditCardInvoice $invoice,
    ): void {
        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('credit-card-invoices.close', $invoice), [
                'statement_amount' => $invoice->calculated_amount,
            ])
            ->assertRedirect(route('credit-card-invoices.show', $invoice))
            ->assertSessionHasNoErrors();
    }

    private function createCardPurchase(
        User $user,
        Workspace $workspace,
        CreditCard $card,
        string $amount,
    ): FinancialTransaction {
        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                ...$this->cardPurchaseData($card),
                'amount' => $amount,
                'installment_count' => 1,
            ])
            ->assertSessionHasNoErrors();

        return FinancialTransaction::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function cardPurchaseData(CreditCard $card): array
    {
        return [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-20',
            'description' => 'Compra no cartão',
            'amount' => '100.00',
            'financial_account_id' => null,
            'credit_card_id' => $card->id,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::CreditCard->value,
            'payee_name' => 'Loja teste',
            'payment_instructions' => null,
            'due_date' => null,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ];
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
