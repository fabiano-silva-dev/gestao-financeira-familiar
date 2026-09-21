<?php

namespace Tests\Feature;

use App\Enums\AccountMovementType;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionStatus;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\TransferService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_guest_cannot_access_reconciliation(): void
    {
        $this->get(route('reconciliation.index'))
            ->assertRedirect(route('login'));
    }

    public function test_page_prioritizes_compatible_movement_by_date_and_description(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
        ]);
        $otherAccount = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-89.90',
            '2026-09-10',
            'Energia elétrica',
        );
        $best = $this->movement(
            $workspace,
            $account,
            '-89.90',
            '2026-09-10',
            'Energia elétrica',
            AccountMovementType::ExpensePayment,
        );
        $this->movement(
            $workspace,
            $account,
            '-89.90',
            '2026-08-01',
            'Outra despesa',
        );
        $this->movement($workspace, $account, '89.90', '2026-09-10', 'Crédito oposto');
        $this->movement($workspace, $otherAccount, '-89.90', '2026-09-10', 'Conta errada');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reconciliation/index')
                ->has('entries', 1)
                ->where('entries.0.id', $entry->id)
                ->where('entries.0.candidates.0.movement_id', $best->id)
                ->where('entries.0.candidates.0.confidence', 'high')
                ->where('entries.0.candidates.0.is_suggestion', true)
                ->where('entries.0.candidates.1.is_suggestion', false)
                ->has('entries.0.candidates', 2)
                ->where('pendingEntriesCount', 1)
                ->where('unmatchedMovementsCount', 4)
            );
    }

    public function test_user_can_reconcile_matching_bank_and_account_movements(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-150.00');
        $movement = $this->movement($workspace, $account, '-150.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.store', $entry), [
                'account_movement_id' => $movement->id,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $movement->refresh();
        $this->assertTrue($entry->is_reconciled);
        $this->assertSame($movement->id, $entry->account_movement_id);
        $this->assertSame($user->id, $entry->reconciled_by);
        $this->assertNotNull($entry->reconciled_at);
        $this->assertTrue($movement->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('account_movements', 1);
    }

    public function test_reconciliation_requires_same_account_and_signed_amount(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $otherAccount = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-100.00');
        $wrongAccount = $this->movement($workspace, $otherAccount, '-100.00');
        $wrongAmount = $this->movement($workspace, $account, '-99.99');
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.store', $entry), [
            'account_movement_id' => $wrongAccount->id,
        ])->assertSessionHasErrors('account_movement_id');

        $request->post(route('reconciliation.store', $entry), [
            'account_movement_id' => $wrongAmount->id,
        ])->assertSessionHasErrors('account_movement_id');

        $this->assertFalse($entry->fresh()->is_reconciled);
        $this->assertFalse($wrongAccount->fresh()->is_reconciled);
        $this->assertFalse($wrongAmount->fresh()->is_reconciled);
    }

    public function test_same_account_movement_cannot_be_used_twice(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $firstEntry = $this->bankEntry($workspace, $account, '-75.00', '2026-09-10');
        $secondEntry = $this->bankEntry($workspace, $account, '-75.00', '2026-09-11');
        $movement = $this->movement($workspace, $account, '-75.00');
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.store', $firstEntry), [
            'account_movement_id' => $movement->id,
        ])->assertSessionHasNoErrors();
        $request->post(route('reconciliation.store', $secondEntry), [
            'account_movement_id' => $movement->id,
        ])->assertSessionHasErrors('account_movement_id');

        $this->assertTrue($firstEntry->fresh()->is_reconciled);
        $this->assertFalse($secondEntry->fresh()->is_reconciled);
    }

    public function test_user_can_undo_reconciliation(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '2500.00');
        $movement = $this->movement(
            $workspace,
            $account,
            '2500.00',
            type: AccountMovementType::IncomeReceipt,
        );
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.store', $entry), [
            'account_movement_id' => $movement->id,
        ])->assertSessionHasNoErrors();
        $request->delete(route('reconciliation.destroy', $entry))
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertFalse($entry->is_reconciled);
        $this->assertNull($entry->account_movement_id);
        $this->assertNull($entry->reconciled_by);
        $this->assertNull($entry->reconciled_at);
        $this->assertFalse($movement->fresh()->is_reconciled);
    }

    public function test_each_side_of_a_transfer_is_reconciled_with_its_own_bank_entry(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create();
        $destination = FinancialAccount::factory()->for($workspace)->create();
        $transfer = app(TransferService::class)->create($workspace, [
            'transaction_date' => '2026-09-10',
            'description' => 'Transferência para reserva',
            'amount' => '300.00',
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);
        $outgoing = $transfer->accountMovements()
            ->where('type', AccountMovementType::TransferOut)
            ->sole();
        $incoming = $transfer->accountMovements()
            ->where('type', AccountMovementType::TransferIn)
            ->sole();
        $sourceEntry = $this->bankEntry($workspace, $source, '-300.00');
        $destinationEntry = $this->bankEntry($workspace, $destination, '300.00');
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.store', $sourceEntry), [
            'account_movement_id' => $outgoing->id,
        ])->assertSessionHasNoErrors();
        $request->post(route('reconciliation.store', $destinationEntry), [
            'account_movement_id' => $incoming->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($outgoing->id, $sourceEntry->fresh()->account_movement_id);
        $this->assertSame($incoming->id, $destinationEntry->fresh()->account_movement_id);
        $this->assertTrue($outgoing->fresh()->is_reconciled);
        $this->assertTrue($incoming->fresh()->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('account_movements', 2);
    }

    public function test_movement_from_another_workspace_cannot_be_reconciled(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-30.00');
        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();
        $otherMovement = $this->movement($otherWorkspace, $otherAccount, '-30.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.store', $entry), [
                'account_movement_id' => $otherMovement->id,
            ])
            ->assertSessionHasErrors('account_movement_id');

        $this->assertFalse($entry->fresh()->is_reconciled);
    }

    public function test_reconciled_movement_must_be_unlinked_before_financial_change(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-45.00');
        $movement = $this->movement($workspace, $account, '-45.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.store', $entry), [
                'account_movement_id' => $movement->id,
            ])
            ->assertSessionHasNoErrors();

        try {
            $movement->refresh()->update(['amount' => '-46.00']);
            $this->fail('A alteração deveria exigir a remoção da conciliação.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reconciliation', $exception->errors());
        }

        $this->assertSame('-45.00', $movement->fresh()->amount);
        $this->assertTrue($entry->fresh()->is_reconciled);
    }

    /** @return array{User, Workspace} */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }

    private function bankEntry(
        Workspace $workspace,
        FinancialAccount $account,
        string $amount,
        string $occurredOn = '2026-09-10',
        string $description = 'Movimento bancário',
    ): BankStatementEntry {
        $this->sequence++;
        $suffix = str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT);
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'type' => FinancialImportType::Ofx,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => "extrato-{$suffix}.ofx",
            'file_hash' => hash('sha256', "file-{$workspace->id}-{$suffix}"),
            'deduplication_key' => hash('sha256', "import-{$workspace->id}-{$suffix}"),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'imported_at' => now(),
        ]);

        return BankStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'financial_account_id' => $account->id,
            'external_id' => "fit-{$suffix}",
            'deduplication_key' => hash('sha256', "entry-{$workspace->id}-{$suffix}"),
            'occurred_on' => $occurredOn,
            'amount' => $amount,
            'transaction_type' => str_starts_with($amount, '-') ? 'DEBIT' : 'CREDIT',
            'description' => $description,
            'memo' => null,
            'is_reconciled' => false,
        ]);
    }

    private function movement(
        Workspace $workspace,
        FinancialAccount $account,
        string $amount,
        string $occurredOn = '2026-09-10',
        string $description = 'Lançamento financeiro',
        AccountMovementType $type = AccountMovementType::Adjustment,
    ): AccountMovement {
        return AccountMovement::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'occurred_on' => $occurredOn,
            'description' => $description,
            'amount' => $amount,
            'type' => $type,
            'is_reconciled' => false,
        ]);
    }
}
