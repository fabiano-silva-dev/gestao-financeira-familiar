<?php

namespace Tests\Feature;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallmentPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_installment_purchase_preserves_total_and_creates_individual_commitments(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                'type' => FinancialTransactionType::Expense->value,
                'transaction_date' => '2026-09-20',
                'competence_date' => '2026-09-20',
                'description' => 'Notebook',
                'amount' => '100.00',
                'installment_count' => 3,
                'financial_account_id' => $account->id,
                'credit_card_id' => null,
                'category_id' => null,
                'family_member_id' => null,
                'payment_method' => PaymentMethod::Boleto->value,
                'payee_name' => 'Loja',
                'payment_instructions' => 'Boleto mensal',
                'due_date' => '2026-10-10',
                'settled_on' => null,
                'status' => FinancialTransactionStatus::Confirmed->value,
                'notes' => null,
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $parent = FinancialTransaction::query()
            ->whereNull('parent_transaction_id')
            ->sole();

        $this->assertSame('100.00', $parent->amount);
        $this->assertSame(3, $parent->installment_count);
        $this->assertNull($parent->settled_on);

        $installments = $parent->installments()->get();

        $this->assertCount(3, $installments);
        $this->assertSame(
            ['33.33', '33.33', '33.34'],
            $installments->pluck('amount')->all(),
        );
        $this->assertSame(
            ['2026-10-10', '2026-11-10', '2026-12-10'],
            $installments
                ->map(fn (FinancialTransaction $entry): string => $entry->due_date->toDateString())
                ->all(),
        );
        $this->assertSame(
            ['2026-10-10', '2026-11-10', '2026-12-10'],
            $installments
                ->map(fn (FinancialTransaction $entry): string => $entry->competence_date->toDateString())
                ->all(),
        );

        $this->assertDatabaseCount('account_movements', 0);

        $firstInstallment = $installments->firstOrFail();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('transactions.toggle-settlement', $firstInstallment))
            ->assertRedirect(route('transactions.index'));

        $firstInstallment->refresh();

        $this->assertSame('2026-09-20', $firstInstallment->settled_on?->toDateString());
        $movement = $firstInstallment->accountMovements()->sole();
        $this->assertSame('-33.33', $movement->amount);
        $this->assertSame($account->id, $movement->financial_account_id);
    }

    public function test_credit_card_installments_follow_card_cycle_without_account_movements(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 15,
            'due_day' => 25,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                'type' => FinancialTransactionType::Expense->value,
                'transaction_date' => '2026-09-20',
                'competence_date' => '2026-09-20',
                'description' => 'Compra no cartão',
                'amount' => '299.90',
                'installment_count' => 3,
                'financial_account_id' => null,
                'credit_card_id' => $card->id,
                'category_id' => null,
                'family_member_id' => null,
                'payment_method' => PaymentMethod::CreditCard->value,
                'payee_name' => 'Loja',
                'payment_instructions' => null,
                'due_date' => null,
                'settled_on' => null,
                'status' => FinancialTransactionStatus::Confirmed->value,
                'notes' => null,
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $parent = FinancialTransaction::query()
            ->whereNull('parent_transaction_id')
            ->sole();

        $installments = $parent->installments()->get();

        $this->assertSame(
            ['2026-10-25', '2026-11-25', '2026-12-25'],
            $installments
                ->map(fn (FinancialTransaction $entry): string => $entry->due_date->toDateString())
                ->all(),
        );
        $this->assertSame(
            ['99.96', '99.96', '99.98'],
            $installments->pluck('amount')->all(),
        );
        $this->assertDatabaseCount('account_movements', 0);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('transactions.toggle-settlement', $installments->firstOrFail()))
            ->assertStatus(422);
    }

    public function test_installments_are_not_listed_as_separate_main_entries(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                'type' => FinancialTransactionType::Expense->value,
                'transaction_date' => '2026-09-20',
                'competence_date' => '2026-09-20',
                'description' => 'Curso',
                'amount' => '240.00',
                'installment_count' => 2,
                'financial_account_id' => $account->id,
                'credit_card_id' => null,
                'category_id' => null,
                'family_member_id' => null,
                'payment_method' => PaymentMethod::Pix->value,
                'payee_name' => null,
                'payment_instructions' => null,
                'due_date' => '2026-10-05',
                'settled_on' => null,
                'status' => FinancialTransactionStatus::Confirmed->value,
                'notes' => null,
            ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries', 1)
                ->where('entries.0.description', 'Curso')
                ->where('entries.0.installment_count', 2)
                ->has('entries.0.installments', 2)
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
