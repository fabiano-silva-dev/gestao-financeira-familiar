<?php

namespace Tests\Feature;

use App\Enums\AccountMovementType;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Services\Finance\TransferService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinancialEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_financial_entries(): void
    {
        $this->get(route('transactions.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_only_lists_income_and_expenses_from_current_workspace(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $currentAccount = FinancialAccount::factory()->for($currentWorkspace)->create();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();

        $expense = $this->createEntry(
            $currentWorkspace,
            $currentAccount,
            FinancialTransactionType::Expense,
            ['description' => 'Despesa visível'],
        );
        $this->createEntry(
            $currentWorkspace,
            $currentAccount,
            FinancialTransactionType::Income,
            ['description' => 'Receita visível'],
        );
        $this->createEntry(
            $otherWorkspace,
            $otherAccount,
            FinancialTransactionType::Expense,
            ['description' => 'Despesa de outro workspace'],
        );
        $transferDestination = FinancialAccount::factory()
            ->for($currentWorkspace)
            ->create();
        app(TransferService::class)->create($currentWorkspace, [
            'transaction_date' => '2026-09-20',
            'description' => 'Transferência não listada',
            'amount' => '50.00',
            'source_account_id' => $currentAccount->id,
            'destination_account_id' => $transferDestination->id,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('transactions/index')
                ->has('entries', 2)
                ->where('entries.1.id', $expense->id)
                ->where('entries.1.description', 'Despesa visível')
            );
    }

    public function test_user_can_create_confirmed_pix_expense_with_payment_instructions(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $category = Category::factory()->for($workspace)->create();
        $member = FamilyMember::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                ...$this->validEntryData(
                    FinancialTransactionType::Expense,
                    $account,
                ),
                'description' => 'Vôlei e handebol',
                'amount' => '125.50',
                'category_id' => $category->id,
                'family_member_id' => $member->id,
                'payee_name' => 'Escola de esportes',
                'payment_instructions' => 'PIX para nome@provedor.com',
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $expense = FinancialTransaction::query()->sole();

        $this->assertSame(FinancialTransactionType::Expense, $expense->type);
        $this->assertSame(PaymentMethod::Pix, $expense->payment_method);
        $this->assertSame('Escola de esportes', $expense->payee_name);
        $this->assertSame('PIX para nome@provedor.com', $expense->payment_instructions);
        $this->assertSame($category->id, $expense->category_id);
        $this->assertSame($member->id, $expense->family_member_id);

        $movement = $expense->accountMovements()->sole();
        $this->assertSame(AccountMovementType::ExpensePayment, $movement->type);
        $this->assertSame('-125.50', $movement->amount);
        $this->assertCurrentBalance($user, $workspace, '874.50');
    }

    public function test_planned_expense_only_changes_balance_after_settlement(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $expense = $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            [
                'amount' => '100.00',
                'status' => FinancialTransactionStatus::Planned->value,
                'due_date' => '2026-10-10',
            ],
        );

        $this->assertCurrentBalance($user, $workspace, '1000.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('transactions.advance-status', $expense))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame(
            FinancialTransactionStatus::Confirmed,
            $expense->fresh()->status,
        );
        $this->assertNull($expense->fresh()->settled_on);
        $this->assertCurrentBalance($user, $workspace, '1000.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('transactions.toggle-settlement', $expense))
            ->assertRedirect(route('transactions.index'));

        $this->assertNotNull($expense->fresh()->settled_on);
        $this->assertCurrentBalance($user, $workspace, '900.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('transactions.toggle-settlement', $expense))
            ->assertRedirect(route('transactions.index'));

        $this->assertNull($expense->fresh()->settled_on);
        $this->assertCurrentBalance($user, $workspace, '1000.00');
    }

    public function test_credit_card_expense_does_not_create_account_movement(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                ...$this->validEntryData(FinancialTransactionType::Expense),
                'payment_method' => PaymentMethod::CreditCard->value,
                'credit_card_id' => $card->id,
                'financial_account_id' => null,
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $expense = FinancialTransaction::query()->sole();

        $this->assertSame($card->id, $expense->credit_card_id);
        $this->assertDatabaseCount('account_movements', 0);
    }

    public function test_user_can_create_income_that_increases_account_balance(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                ...$this->validEntryData(
                    FinancialTransactionType::Income,
                    $account,
                ),
                'amount' => '500.00',
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $income = FinancialTransaction::query()->sole();
        $movement = $income->accountMovements()->sole();

        $this->assertSame(FinancialTransactionType::Income, $income->type);
        $this->assertSame(AccountMovementType::IncomeReceipt, $movement->type);
        $this->assertSame('500.00', $movement->amount);
        $this->assertCurrentBalance($user, $workspace, '1500.00');
    }

    public function test_entry_validates_future_payment_and_workspace_references(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();
        $otherCard = CreditCard::factory()->for($otherWorkspace)->create();
        $otherCategory = Category::factory()->for($otherWorkspace)->create();
        $otherMember = FamilyMember::factory()->for($otherWorkspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('transactions.store'), [
            ...$this->validEntryData(FinancialTransactionType::Expense),
            'status' => FinancialTransactionStatus::Planned->value,
            'due_date' => null,
            'financial_account_id' => $otherAccount->id,
            'category_id' => $otherCategory->id,
            'family_member_id' => $otherMember->id,
        ])->assertSessionHasErrors([
            'due_date',
            'financial_account_id',
            'category_id',
            'family_member_id',
        ]);

        $request->post(route('transactions.store'), [
            ...$this->validEntryData(FinancialTransactionType::Expense),
            'payment_method' => PaymentMethod::CreditCard->value,
            'financial_account_id' => null,
            'credit_card_id' => $otherCard->id,
        ])->assertSessionHasErrors('credit_card_id');

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_updating_entry_updates_its_account_movement(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $oldAccount = FinancialAccount::factory()->for($workspace)->create();
        $newAccount = FinancialAccount::factory()->for($workspace)->create();
        $expense = $this->createEntry(
            $workspace,
            $oldAccount,
            FinancialTransactionType::Expense,
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('transactions.update', $expense), [
                ...$this->validEntryData(
                    FinancialTransactionType::Expense,
                    $newAccount,
                ),
                'description' => 'Despesa atualizada',
                'amount' => '245.80',
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $movement = $expense->accountMovements()->sole();

        $this->assertSame('Despesa atualizada', $expense->description);
        $this->assertSame('245.80', $expense->amount);
        $this->assertSame($newAccount->id, $movement->financial_account_id);
        $this->assertSame('-245.80', $movement->amount);
    }

    public function test_entry_from_another_active_workspace_cannot_be_changed(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $currentAccount = FinancialAccount::factory()->for($currentWorkspace)->create();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();
        $otherEntry = $this->createEntry(
            $otherWorkspace,
            $otherAccount,
            FinancialTransactionType::Expense,
            ['description' => 'Descrição protegida'],
        );

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->put(route('transactions.update', $otherEntry), [
                ...$this->validEntryData(
                    FinancialTransactionType::Expense,
                    $currentAccount,
                ),
                'description' => 'Tentativa indevida',
            ])
            ->assertNotFound();

        $this->assertSame(
            'Descrição protegida',
            $otherEntry->fresh()->description,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEntry(
        Workspace $workspace,
        FinancialAccount $account,
        FinancialTransactionType $type,
        array $overrides = [],
    ): FinancialTransaction {
        return app(FinancialEntryService::class)->create($workspace, [
            ...$this->validEntryData($type, $account),
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validEntryData(
        FinancialTransactionType $type,
        ?FinancialAccount $account = null,
    ): array {
        return [
            'type' => $type->value,
            'transaction_date' => '2026-09-20',
            'description' => $type === FinancialTransactionType::Expense
                ? 'Despesa de teste'
                : 'Receita de teste',
            'amount' => '100.00',
            'financial_account_id' => $account?->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix->value,
            'payee_name' => null,
            'payment_instructions' => null,
            'due_date' => null,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ];
    }

    private function assertCurrentBalance(
        User $user,
        Workspace $workspace,
        string $expected,
    ): void {
        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.current_balance', $expected)
            );
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
