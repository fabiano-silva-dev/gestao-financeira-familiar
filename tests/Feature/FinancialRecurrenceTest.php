<?php

namespace Tests\Feature;

use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialRecurrence;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinancialRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_pix_recurrence_generates_future_planned_commitments(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('recurrences.store'), [
                ...$this->validRecurrenceData($account),
                'description' => 'Vôlei e handebol',
                'amount' => '125.50',
                'payee_name' => 'Escola de esportes',
                'payment_instructions' => 'PIX para nome@provedor.com',
                'starts_on' => '2026-09-25',
            ])
            ->assertRedirect(route('recurrences.index'))
            ->assertSessionHasNoErrors();

        $recurrence = FinancialRecurrence::query()->sole();
        $transactions = $recurrence->transactions()
            ->orderBy('recurrence_occurrence_date')
            ->get();

        $this->assertTrue($recurrence->is_active);
        $this->assertSame(PaymentMethod::Pix, $recurrence->payment_method);
        $this->assertSame('Escola de esportes', $recurrence->payee_name);
        $this->assertSame('PIX para nome@provedor.com', $recurrence->payment_instructions);
        $this->assertCount(3, $transactions);
        $this->assertSame(
            ['2026-09-25', '2026-10-25', '2026-11-25'],
            $transactions
                ->map(fn (FinancialTransaction $entry): string => $entry
                    ->recurrence_occurrence_date
                    ->toDateString())
                ->all(),
        );

        foreach ($transactions as $transaction) {
            $this->assertSame(
                FinancialTransactionStatus::Planned,
                $transaction->status,
            );
            $this->assertSame(
                FinancialTransactionOrigin::Recurrence,
                $transaction->origin,
            );
            $this->assertNull($transaction->settled_on);
            $this->assertSame(
                $transaction->recurrence_occurrence_date->toDateString(),
                $transaction->due_date?->toDateString(),
            );
            $this->assertSame('PIX para nome@provedor.com', $transaction->payment_instructions);
        }

        $this->assertDatabaseCount('account_movements', 0);
    }

    public function test_recurrence_generation_is_idempotent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('recurrences.store'), [
                ...$this->validRecurrenceData($account),
                'starts_on' => '2026-09-25',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('financial_transactions', 3);

        $this->artisan('finance:generate-recurrences', [
            '--through' => '2027-01-01',
        ])->assertSuccessful();

        $this->assertDatabaseCount('financial_transactions', 4);

        $this->artisan('finance:generate-recurrences', [
            '--through' => '2027-01-01',
        ])->assertSuccessful();

        $this->assertDatabaseCount('financial_transactions', 4);
    }

    public function test_pausing_recurrence_removes_only_future_planned_occurrences(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('recurrences.store'), [
                ...$this->validRecurrenceData($account),
                'starts_on' => '2026-09-25',
            ])
            ->assertSessionHasNoErrors();

        $recurrence = FinancialRecurrence::query()->sole();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('recurrences.toggle-status', $recurrence))
            ->assertRedirect(route('recurrences.index'));

        $this->assertFalse($recurrence->fresh()->is_active);
        $this->assertDatabaseCount('financial_transactions', 0);

        $this->artisan('finance:generate-recurrences', [
            '--through' => '2027-01-01',
        ])->assertSuccessful();

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_card_recurrence_materializes_only_the_due_occurrence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 5,
            'due_day' => 12,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('recurrences.store'), [
                'type' => FinancialTransactionType::Expense->value,
                'description' => 'Streaming',
                'amount' => '59.90',
                'financial_account_id' => null,
                'credit_card_id' => $card->id,
                'category_id' => null,
                'family_member_id' => null,
                'payment_method' => PaymentMethod::CreditCard->value,
                'payee_name' => 'Streaming',
                'payment_instructions' => null,
                'frequency' => 'monthly',
                'interval' => 1,
                'starts_on' => '2026-09-20',
                'ends_on' => null,
                'notes' => null,
            ])
            ->assertRedirect(route('recurrences.index'))
            ->assertSessionHasNoErrors();

        $recurrence = FinancialRecurrence::query()->sole();
        $transaction = $recurrence->transactions()->sole();

        $this->assertSame(
            FinancialTransactionStatus::Confirmed,
            $transaction->status,
        );
        $this->assertSame($card->id, $transaction->credit_card_id);
        $this->assertNull($transaction->settled_on);
        $this->assertDatabaseCount('transaction_installments', 1);
        $this->assertDatabaseCount('credit_card_invoices', 1);
        $this->assertDatabaseCount('account_movements', 0);

        $invoice = CreditCardInvoice::query()->sole();
        $this->assertSame('2026-10-12', $invoice->due_date->toDateString());
    }

    public function test_recurrence_index_returns_six_month_projection(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('recurrences.store'), [
                ...$this->validRecurrenceData($account),
                'amount' => '100.00',
                'starts_on' => '2026-09-25',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('recurrences.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('recurrences/index')
                ->has('recurrences', 1)
                ->has('projection', 6)
                ->where('projection.0.month', '2026-09-01')
                ->where('projection.0.expenses', '100.00')
                ->where('projection.1.expenses', '100.00')
            );
    }

    public function test_recurrence_cannot_reference_another_workspace_account(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('recurrences.store'), [
                ...$this->validRecurrenceData($otherAccount),
            ])
            ->assertSessionHasErrors('financial_account_id');

        $this->assertDatabaseCount('financial_recurrences', 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function validRecurrenceData(FinancialAccount $account): array
    {
        return [
            'type' => FinancialTransactionType::Expense->value,
            'description' => 'Mensalidade',
            'amount' => '100.00',
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix->value,
            'payee_name' => null,
            'payment_instructions' => null,
            'frequency' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-09-25',
            'ends_on' => null,
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
