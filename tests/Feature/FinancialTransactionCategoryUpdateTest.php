<?php

namespace Tests\Feature;

use App\Enums\CategoryType;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialTransactionCategoryUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_category_from_transaction_listing(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $oldCategory = Category::factory()->for($workspace)->create([
            'type' => CategoryType::Expense,
        ]);
        $newCategory = Category::factory()->for($workspace)->create([
            'type' => CategoryType::Expense,
        ]);
        $entry = $this->createEntry($workspace, $account, [
            'category_id' => $oldCategory->id,
        ]);

        $this->asWorkspace($user, $workspace)
            ->patch(route('transactions.update-category', $entry), [
                'category_id' => $newCategory->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($newCategory->id, $entry->fresh()->category_id);
    }

    public function test_user_can_update_category_for_multiple_transactions_at_once(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create([
            'type' => CategoryType::Expense,
        ]);
        $first = $this->createEntry($workspace, $account);
        $second = $this->createEntry($workspace, $account);

        $this->asWorkspace($user, $workspace)
            ->patch(route('transactions.bulk-update-category'), [
                'entry_ids' => [$first->id, $second->id],
                'category_id' => $category->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($category->id, $first->fresh()->category_id);
        $this->assertSame($category->id, $second->fresh()->category_id);
    }

    public function test_bulk_update_is_atomic_when_an_entry_belongs_to_another_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create([
            'type' => CategoryType::Expense,
        ]);
        $currentEntry = $this->createEntry($workspace, $account);

        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();
        $otherEntry = $this->createEntry($otherWorkspace, $otherAccount);

        $this->asWorkspace($user, $workspace)
            ->patch(route('transactions.bulk-update-category'), [
                'entry_ids' => [$currentEntry->id, $otherEntry->id],
                'category_id' => $category->id,
            ])
            ->assertNotFound();

        $this->assertNull($currentEntry->fresh()->category_id);
        $this->assertNull($otherEntry->fresh()->category_id);
    }

    public function test_category_from_another_workspace_cannot_be_used(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->createEntry($workspace, $account);

        $otherWorkspace = Workspace::factory()->create();
        $otherCategory = Category::factory()->for($otherWorkspace)->create([
            'type' => CategoryType::Expense,
        ]);

        $this->asWorkspace($user, $workspace)
            ->patch(route('transactions.update-category', $entry), [
                'category_id' => $otherCategory->id,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertNull($entry->fresh()->category_id);
    }

    public function test_category_must_match_transaction_type(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->createEntry($workspace, $account);
        $incomeCategory = Category::factory()->for($workspace)->create([
            'type' => CategoryType::Income,
        ]);

        $this->asWorkspace($user, $workspace)
            ->patch(route('transactions.update-category', $entry), [
                'category_id' => $incomeCategory->id,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertNull($entry->fresh()->category_id);
    }

    public function test_bulk_category_update_does_not_change_other_transaction_fields(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create([
            'type' => CategoryType::Expense,
        ]);
        $entry = $this->createEntry($workspace, $account, [
            'description' => 'Compra preservada',
            'amount' => '245.80',
        ]);
        $before = $entry->only([
            'type',
            'transaction_date',
            'competence_date',
            'description',
            'amount',
            'financial_account_id',
            'settled_on',
            'status',
        ]);

        $this->asWorkspace($user, $workspace)
            ->patch(route('transactions.bulk-update-category'), [
                'entry_ids' => [$entry->id],
                'category_id' => $category->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $entry->fresh();

        $this->assertSame($category->id, $fresh->category_id);
        $this->assertEquals($before, $fresh->only(array_keys($before)));
    }

    public function test_category_update_preserves_existing_reconciliation(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create([
            'type' => CategoryType::Expense,
        ]);
        $entry = $this->createEntry($workspace, $account);
        $movement = $entry->accountMovements()->sole();
        $movement->update(['is_reconciled' => true]);

        $this->asWorkspace($user, $workspace)
            ->patch(route('transactions.update-category', $entry), [
                'category_id' => $category->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($category->id, $entry->fresh()->category_id);
        $this->assertSame($movement->id, $entry->accountMovements()->sole()->id);
        $this->assertTrue($movement->fresh()->is_reconciled);
    }

    public function test_transfer_cannot_receive_category_through_bulk_endpoint(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create();
        $destination = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create([
            'type' => CategoryType::Expense,
        ]);
        $transfer = $workspace->financialTransactions()->create([
            'type' => FinancialTransactionType::Transfer,
            'transaction_date' => '2026-09-20',
            'competence_date' => '2026-09-20',
            'description' => 'Transferência protegida',
            'amount' => '80.00',
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'status' => FinancialTransactionStatus::Confirmed,
            'origin' => 'manual',
        ]);

        $this->asWorkspace($user, $workspace)
            ->patch(route('transactions.bulk-update-category'), [
                'entry_ids' => [$transfer->id],
                'category_id' => $category->id,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertNull($transfer->fresh()->category_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEntry(
        Workspace $workspace,
        FinancialAccount $account,
        array $overrides = [],
    ): FinancialTransaction {
        return app(FinancialEntryService::class)->create($workspace, [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-20',
            'competence_date' => '2026-09-20',
            'description' => 'Despesa de teste',
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

    private function asWorkspace(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);
    }
}
