<?php

namespace Tests\Feature;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\BankStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialRecurrence;
use App\Models\FinancialRecurrenceOmission;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
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

    public function test_generation_start_before_today_creates_retroactive_planned_commitments(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('recurrences.store'), [
                ...$this->validRecurrenceData($account),
                'description' => 'Negociação Sumaia',
                'amount' => '200.00',
                'starts_on' => '2026-09-01',
                'generation_started_on' => '2026-09-01',
            ])
            ->assertRedirect(route('recurrences.index'))
            ->assertSessionHasNoErrors();

        $recurrence = FinancialRecurrence::query()->sole();
        $transactions = $recurrence->transactions()
            ->orderBy('recurrence_occurrence_date')
            ->get();

        $this->assertSame('2026-09-01', $recurrence->generation_started_on->toDateString());
        $this->assertSame(
            ['2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01'],
            $transactions
                ->map(fn (FinancialTransaction $entry): string => $entry
                    ->recurrence_occurrence_date
                    ->toDateString())
                ->all(),
        );

        foreach ($transactions as $transaction) {
            $this->assertSame(FinancialTransactionStatus::Planned, $transaction->status);
            $this->assertNull($transaction->settled_on);
        }

        $this->assertDatabaseCount('account_movements', 0);
    }

    public function test_updating_generation_start_creates_the_missing_past_occurrence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('recurrences.store'), [
            ...$this->validRecurrenceData($account),
            'starts_on' => '2026-09-01',
            'generation_started_on' => '2026-09-23',
        ])->assertSessionHasNoErrors();

        $recurrence = FinancialRecurrence::query()->sole();

        $this->assertSame(
            ['2026-10-01', '2026-11-01', '2026-12-01'],
            $recurrence->transactions()
                ->orderBy('recurrence_occurrence_date')
                ->get()
                ->map(fn (FinancialTransaction $entry): string => $entry
                    ->recurrence_occurrence_date
                    ->toDateString())
                ->all(),
        );

        $request->put(route('recurrences.update', $recurrence), [
            ...$this->validRecurrenceData($account),
            'starts_on' => '2026-09-01',
            'generation_started_on' => '2026-09-01',
        ])->assertSessionHasNoErrors();

        $dates = $recurrence->transactions()
            ->orderBy('recurrence_occurrence_date')
            ->get()
            ->map(fn (FinancialTransaction $entry): string => $entry
                ->recurrence_occurrence_date
                ->toDateString())
            ->all();

        $this->assertSame(
            ['2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01'],
            $dates,
        );
        $this->assertSame(
            FinancialTransactionStatus::Planned,
            $recurrence->transactions()->orderBy('recurrence_occurrence_date')->firstOrFail()->status,
        );
        $this->assertDatabaseCount('account_movements', 0);
    }

    public function test_generation_start_before_first_occurrence_is_rejected(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('recurrences.store'), [
                ...$this->validRecurrenceData($account),
                'starts_on' => '2026-09-01',
                'generation_started_on' => '2026-08-01',
            ])
            ->assertSessionHasErrors('generation_started_on');

        $this->assertDatabaseCount('financial_recurrences', 0);
    }

    public function test_already_settled_recurrence_marks_current_occurrence_as_paid(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '3000.00',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('recurrences.store'), [
                ...$this->validRecurrenceData($account),
                'description' => 'Aluguel',
                'amount' => '2177.34',
                'starts_on' => '2026-06-08',
                'already_settled' => '1',
            ])
            ->assertRedirect(route('recurrences.index'))
            ->assertSessionHasNoErrors();

        $recurrence = FinancialRecurrence::query()->sole();
        $transactions = $recurrence->transactions()
            ->orderBy('recurrence_occurrence_date')
            ->get();
        $current = $transactions->first();
        $future = $transactions->skip(1);

        $this->assertSame('2026-09-08', $current?->recurrence_occurrence_date?->toDateString());
        $this->assertSame(FinancialTransactionStatus::Confirmed, $current?->status);
        $this->assertSame('2026-09-08', $current?->settled_on?->toDateString());
        $this->assertNotNull($current?->accountMovements()->first());

        foreach ($future as $transaction) {
            $this->assertSame(FinancialTransactionStatus::Planned, $transaction->status);
            $this->assertNull($transaction->settled_on);
        }

        $this->assertGreaterThanOrEqual(2, $transactions->count());
        $this->assertDatabaseCount('account_movements', 1);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('metrics.current_balance', '822.66')
                ->where('metrics.expenses', '2177.34')
                ->where('recentEntries.0.description', 'Aluguel')
                ->where('recentEntries.0.status_label', 'Pago')
                ->where('upcomingEntries.0.description', 'Aluguel')
                ->where('upcomingEntries.0.date', '2026-10-08')
            );
    }

    public function test_unchecking_paid_recurrence_deletes_settlement_and_keeps_occurrence_pending(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('recurrences.store'), [
            ...$this->validRecurrenceData($account),
            'description' => 'Condomínio',
            'amount' => '569.19',
            'starts_on' => '2026-08-10',
            'already_settled' => '1',
        ])->assertSessionHasNoErrors();

        $occurrence = FinancialRecurrence::query()->sole()
            ->transactions()
            ->whereDate('recurrence_occurrence_date', '2026-09-10')
            ->firstOrFail();

        $this->assertNotNull($occurrence->settled_on);
        $this->assertSame(1, $occurrence->accountMovements()->count());

        $request->patch(route('transactions.revert-recurrence-settlement', $occurrence))
            ->assertRedirect(route('transactions.edit', $occurrence))
            ->assertSessionHasNoErrors();

        $occurrence->refresh();

        $this->assertSame(FinancialTransactionStatus::Planned, $occurrence->status);
        $this->assertNull($occurrence->settled_on);
        $this->assertFalse($occurrence->recurrence_is_overridden);
        $this->assertSame('2026-09-10', $occurrence->due_date?->toDateString());
        $this->assertSame(0, $occurrence->accountMovements()->count());
        $this->assertNotNull(FinancialTransaction::query()->find($occurrence->id));
    }

    public function test_unchecking_paid_recurrence_undoes_bank_reconciliation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('recurrences.store'), [
            ...$this->validRecurrenceData($account),
            'description' => 'Condomínio',
            'amount' => '569.19',
            'starts_on' => '2026-08-10',
            'already_settled' => '1',
        ])->assertSessionHasNoErrors();

        $occurrence = FinancialRecurrence::query()->sole()
            ->transactions()
            ->whereDate('recurrence_occurrence_date', '2026-09-10')
            ->firstOrFail();
        $movement = $occurrence->accountMovements()->sole();
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'type' => FinancialImportType::Ofx,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => 'account_statement.csv',
            'file_hash' => hash('sha256', 'statement-file'),
            'deduplication_key' => hash('sha256', 'statement-import'),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'imported_at' => now(),
        ]);
        $bankEntry = BankStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'financial_account_id' => $account->id,
            'external_id' => 'fit-condominio',
            'deduplication_key' => hash('sha256', 'statement-entry'),
            'occurred_on' => '2026-09-10',
            'amount' => '-569.19',
            'transaction_type' => 'DEBIT',
            'description' => 'Condomínio',
            'memo' => null,
            'is_reconciled' => false,
        ]);

        $request->post(route('reconciliation.store', $bankEntry), [
            'account_movement_id' => $movement->id,
        ])->assertSessionHasNoErrors();

        $request->patch(route('transactions.revert-recurrence-settlement', $occurrence))
            ->assertRedirect(route('transactions.edit', $occurrence))
            ->assertSessionHasNoErrors();

        $occurrence->refresh();
        $bankEntry->refresh();

        $this->assertSame(FinancialTransactionStatus::Planned, $occurrence->status);
        $this->assertNull($occurrence->settled_on);
        $this->assertFalse($occurrence->recurrence_is_overridden);
        $this->assertSame(0, $occurrence->accountMovements()->count());
        $this->assertFalse($bankEntry->is_reconciled);
        $this->assertNull($bankEntry->account_movement_id);
    }

    public function test_deleting_a_recurrence_occurrence_does_not_regenerate_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('recurrences.store'), [
            ...$this->validRecurrenceData($account),
            'description' => 'Imembui',
            'amount' => '1052.37',
            'starts_on' => '2026-08-10',
            'already_settled' => '1',
        ])->assertSessionHasNoErrors();

        $recurrence = FinancialRecurrence::query()->sole();
        $occurrence = $recurrence->transactions()
            ->whereDate('recurrence_occurrence_date', '2026-10-10')
            ->firstOrFail();
        $remaining = $recurrence->transactions()->count() - 1;

        $request->delete(route('transactions.destroy-recurrence-occurrence', $occurrence))
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $this->assertNull(FinancialTransaction::query()->find($occurrence->id));
        $this->assertDatabaseHas('financial_recurrence_omissions', [
            'financial_recurrence_id' => $recurrence->id,
            'occurrence_date' => '2026-10-10',
        ]);
        $this->assertSame($remaining, $recurrence->transactions()->count());

        $request->get(route('payments'))->assertOk();

        $this->assertNull(
            $recurrence->transactions()
                ->whereDate('recurrence_occurrence_date', '2026-10-10')
                ->first(),
        );
        $this->assertSame(1, FinancialRecurrenceOmission::query()->count());
    }

    public function test_deleting_a_settled_recurrence_occurrence_removes_the_cash_movement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('recurrences.store'), [
            ...$this->validRecurrenceData($account),
            'description' => 'Condomínio',
            'amount' => '569.19',
            'starts_on' => '2026-08-10',
            'already_settled' => '1',
        ])->assertSessionHasNoErrors();

        $occurrence = FinancialRecurrence::query()->sole()
            ->transactions()
            ->whereDate('recurrence_occurrence_date', '2026-09-10')
            ->firstOrFail();

        $this->assertSame(1, $occurrence->accountMovements()->count());

        $request->delete(route('transactions.destroy-recurrence-occurrence', $occurrence))
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $this->assertNull(FinancialTransaction::query()->find($occurrence->id));
        $this->assertDatabaseCount('account_movements', 0);

        $request->get(route('payments'))->assertOk();

        $this->assertNull(
            FinancialTransaction::query()
                ->whereDate('recurrence_occurrence_date', '2026-09-10')
                ->first(),
        );
    }

    public function test_manual_entry_cannot_delete_recurrence_occurrence(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = app(FinancialEntryService::class)->create($workspace, [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-10',
            'competence_date' => '2026-09-10',
            'description' => 'Mercado',
            'amount' => '40.00',
            'financial_account_id' => $account->id,
            'payment_method' => PaymentMethod::Pix->value,
            'due_date' => '2026-09-10',
            'settled_on' => '2026-09-10',
            'status' => FinancialTransactionStatus::Confirmed->value,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->delete(route('transactions.destroy-recurrence-occurrence', $entry))
            ->assertSessionHasErrors('entry');

        $this->assertNotNull($entry->fresh());
    }

    public function test_manual_entry_cannot_revert_recurrence_settlement(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = app(FinancialEntryService::class)->create($workspace, [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-10',
            'competence_date' => '2026-09-10',
            'description' => 'Mercado',
            'amount' => '40.00',
            'financial_account_id' => $account->id,
            'payment_method' => PaymentMethod::Pix->value,
            'due_date' => '2026-09-10',
            'settled_on' => '2026-09-10',
            'status' => FinancialTransactionStatus::Confirmed->value,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('transactions.revert-recurrence-settlement', $entry))
            ->assertSessionHasErrors('settlement');

        $this->assertNotNull($entry->fresh()?->settled_on);
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

    public function test_edit_lists_realized_and_pending_recurrence_occurrences(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('recurrences.store'), [
                ...$this->validRecurrenceData($account),
                'description' => 'Condomínio',
                'starts_on' => '2026-06-08',
                'already_settled' => '1',
            ])
            ->assertSessionHasNoErrors();

        $recurrence = FinancialRecurrence::query()->sole();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('recurrences.edit', $recurrence))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('recurrences/edit')
                ->where('recurrence.id', $recurrence->id)
                ->has('occurrences', 4)
                ->where('occurrences.0.status_label', 'A pagar')
                ->where('occurrences.3.status_label', 'Pago')
                ->where('occurrences.3.settled_on', '2026-09-08')
            );
    }

    public function test_deleting_recurrence_archives_rule_and_preserves_realized_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('recurrences.store'), [
            ...$this->validRecurrenceData($account),
            'description' => 'Condomínio',
            'starts_on' => '2026-06-08',
            'already_settled' => '1',
        ])->assertSessionHasNoErrors();

        $recurrence = FinancialRecurrence::query()->sole();
        $paid = $recurrence->transactions()
            ->whereNotNull('settled_on')
            ->sole();

        $this->assertGreaterThan(
            0,
            $recurrence->transactions()
                ->where('status', FinancialTransactionStatus::Planned->value)
                ->whereDate('recurrence_occurrence_date', '>=', '2026-09-21')
                ->count(),
        );

        $request->delete(route('recurrences.destroy', $recurrence))
            ->assertRedirect(route('recurrences.index'))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('financial_recurrences', [
            'id' => $recurrence->id,
            'workspace_id' => $workspace->id,
        ]);
        $this->assertNull(FinancialRecurrence::query()->find($recurrence->id));

        $archived = FinancialRecurrence::withTrashed()
            ->findOrFail($recurrence->id);

        $this->assertFalse($archived->is_active);
        $this->assertSame(1, $archived->transactions()->count());
        $this->assertNotNull(
            $archived->transactions()->find($paid->id),
        );
        $this->assertNotNull($paid->fresh()?->settled_on);

        $request->get(route('recurrences.edit', $recurrence))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('recurrences/archived')
                ->where('description', 'Condomínio')
            );
        $this->assertSame(1, $paid->fresh()?->accountMovements()->count());

        $request->get(route('recurrences.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recurrences', 0)
                ->where('hasRecords', false)
            );
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

    public function test_manual_occurrence_adjustment_survives_template_update_and_pause(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));

        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('recurrences.store'), [
            ...$this->validRecurrenceData($account),
            'starts_on' => '2026-09-25',
        ])->assertSessionHasNoErrors();

        $recurrence = FinancialRecurrence::query()->sole();
        $occurrence = $recurrence->transactions()
            ->orderBy('recurrence_occurrence_date')
            ->firstOrFail();

        $request->put(route('transactions.update', $occurrence), [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-25',
            'competence_date' => '2026-09-25',
            'description' => 'Mensalidade ajustada em setembro',
            'amount' => '140.00',
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix->value,
            'payee_name' => 'Professor substituto',
            'payment_instructions' => 'PIX chave alternativa',
            'due_date' => '2026-09-25',
            'settled_on' => null,
            'status' => FinancialTransactionStatus::Planned->value,
            'notes' => null,
        ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($occurrence->fresh()->recurrence_is_overridden);

        $request->put(route('recurrences.update', $recurrence), [
            ...$this->validRecurrenceData($account),
            'amount' => '200.00',
            'starts_on' => '2026-09-25',
            'payment_instructions' => 'PIX nova chave padrão',
        ])
            ->assertRedirect(route('recurrences.index'))
            ->assertSessionHasNoErrors();

        $occurrence->refresh();

        $this->assertSame('140.00', $occurrence->amount);
        $this->assertSame('PIX chave alternativa', $occurrence->payment_instructions);
        $this->assertSame(3, $recurrence->transactions()->count());

        $request->patch(route('recurrences.toggle-status', $recurrence))
            ->assertRedirect(route('recurrences.index'));

        $this->assertFalse($recurrence->fresh()->is_active);
        $this->assertSame(1, $recurrence->transactions()->count());
        $this->assertTrue(
            $recurrence->transactions()->sole()->recurrence_is_overridden,
        );
    }

    public function test_card_recurrence_generates_twelve_months_as_planned_without_creating_invoices(): void
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
        $transactions = $recurrence->transactions()
            ->orderBy('recurrence_occurrence_date')
            ->get();

        $this->assertCount(12, $transactions);
        $this->assertSame('2026-09-20', $transactions->first()?->recurrence_occurrence_date?->toDateString());
        $this->assertSame('2027-08-20', $transactions->last()?->recurrence_occurrence_date?->toDateString());

        foreach ($transactions as $transaction) {
            $this->assertSame(FinancialTransactionStatus::Planned, $transaction->status);
            $this->assertSame($card->id, $transaction->credit_card_id);
            $this->assertNull($transaction->settled_on);
            $this->assertCount(0, $transaction->installments);
        }

        $this->assertDatabaseCount('transaction_installments', 0);
        $this->assertDatabaseCount('credit_card_invoices', 0);
        $this->assertDatabaseCount('account_movements', 0);
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
                ->where('currentPeriod', '2026-09-01')
                ->where('filters.period', '2026-09')
                ->where('projection.0.month', '2026-09-01')
                ->where('projection.0.expenses', '100.00')
                ->where('projection.1.expenses', '100.00')
            );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('recurrences.index', ['period' => '2026-11']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('recurrences/index')
                ->where('currentPeriod', '2026-11-01')
                ->where('filters.period', '2026-11')
                ->where('projection.0.month', '2026-11-01')
                ->where('projection.0.expenses', '100.00')
            );

        $recurrence = FinancialRecurrence::query()->sole();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('recurrences.edit', $recurrence))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('recurrences/edit')
                ->where('recurrence.id', $recurrence->id)
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
