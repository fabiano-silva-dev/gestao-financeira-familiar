<?php

namespace Tests\Feature;

use App\Enums\AccountMovementType;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\TransferService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_transfers(): void
    {
        $this->get(route('transfers.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_only_lists_transfers_from_current_workspace(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        [$currentSource, $currentDestination] = $this->accountPair($currentWorkspace);
        [$otherSource, $otherDestination] = $this->accountPair($otherWorkspace);

        $visibleTransfer = $this->createTransfer(
            $currentWorkspace,
            $currentSource,
            $currentDestination,
            ['description' => 'Transferência visível'],
        );
        $this->createTransfer(
            $otherWorkspace,
            $otherSource,
            $otherDestination,
            ['description' => 'Transferência de outro workspace'],
        );

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->get(route('transfers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('transfers/index')
                ->has('transfers', 1)
                ->where('transfers.0.id', $visibleTransfer->id)
                ->where('transfers.0.description', 'Transferência visível')
            );
    }

    public function test_confirmed_transfer_creates_linked_outgoing_and_incoming_movements(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        [$source, $destination] = $this->accountPair($workspace);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transfers.store'), [
                ...$this->validTransferData($source, $destination),
                'amount' => '2000.00',
                'description' => 'Reserva mensal',
            ])
            ->assertRedirect(route('transfers.index'))
            ->assertSessionHasNoErrors();

        $transfer = FinancialTransaction::query()->sole();

        $this->assertSame($workspace->id, $transfer->workspace_id);
        $this->assertSame(FinancialTransactionType::Transfer, $transfer->type);
        $this->assertSame(FinancialTransactionStatus::Confirmed, $transfer->status);
        $this->assertSame('2000.00', $transfer->amount);
        $this->assertCount(2, $transfer->accountMovements);

        $outgoing = $transfer->accountMovements
            ->sole(fn ($movement): bool => $movement->type === AccountMovementType::TransferOut);
        $incoming = $transfer->accountMovements
            ->sole(fn ($movement): bool => $movement->type === AccountMovementType::TransferIn);

        $this->assertSame($source->id, $outgoing->financial_account_id);
        $this->assertSame('-2000.00', $outgoing->amount);
        $this->assertSame($destination->id, $incoming->financial_account_id);
        $this->assertSame('2000.00', $incoming->amount);
        $this->assertEqualsWithDelta(
            0,
            (float) $transfer->accountMovements->sum('amount'),
            0.001,
        );
    }

    public function test_transfer_requires_different_accounts_from_current_workspace(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        [$source] = $this->accountPair($currentWorkspace);
        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $currentWorkspace->id]);

        $request->post(route('transfers.store'), [
            ...$this->validTransferData($source, $source),
        ])->assertSessionHasErrors('destination_account_id');

        $request->post(route('transfers.store'), [
            ...$this->validTransferData($source, $otherAccount),
        ])->assertSessionHasErrors('destination_account_id');

        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('account_movements', 0);
    }

    public function test_updating_transfer_updates_both_linked_movements(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        [$source, $destination] = $this->accountPair($workspace);
        $newDestination = FinancialAccount::factory()
            ->for($workspace)
            ->create(['name' => 'Conta nova']);
        $transfer = $this->createTransfer($workspace, $source, $destination);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('transfers.update', $transfer), [
                ...$this->validTransferData($destination, $newDestination),
                'transaction_date' => '2026-10-15',
                'description' => 'Transferência atualizada',
                'amount' => '350.75',
            ])
            ->assertRedirect(route('transfers.index'))
            ->assertSessionHasNoErrors();

        $transfer->refresh()->load('accountMovements');
        $outgoing = $transfer->accountMovements
            ->sole(fn ($movement): bool => $movement->type === AccountMovementType::TransferOut);
        $incoming = $transfer->accountMovements
            ->sole(fn ($movement): bool => $movement->type === AccountMovementType::TransferIn);

        $this->assertSame('Transferência atualizada', $transfer->description);
        $this->assertSame('350.75', $transfer->amount);
        $this->assertSame($destination->id, $outgoing->financial_account_id);
        $this->assertSame('-350.75', $outgoing->amount);
        $this->assertSame($newDestination->id, $incoming->financial_account_id);
        $this->assertSame('350.75', $incoming->amount);
        $this->assertSame('2026-10-15', $incoming->occurred_on->toDateString());
    }

    public function test_only_confirmed_transfer_changes_account_balances(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'A Origem',
            'opening_balance' => '5000.00',
        ]);
        $destination = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'B Destino',
            'opening_balance' => '1000.00',
        ]);
        $transfer = $this->createTransfer(
            $workspace,
            $source,
            $destination,
            [
                'amount' => '2000.00',
                'status' => FinancialTransactionStatus::Planned->value,
            ],
        );
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $this->assertAccountBalances($request, '5000.00', '1000.00');

        $request->patch(route('transfers.advance-status', $transfer))
            ->assertRedirect(route('transfers.index'));
        $this->assertSame(
            FinancialTransactionStatus::Confirmed,
            $transfer->fresh()->status,
        );
        $this->assertAccountBalances($request, '3000.00', '3000.00');

        $request->patch(route('transfers.advance-status', $transfer))
            ->assertRedirect(route('transfers.index'));
        $this->assertSame(
            FinancialTransactionStatus::Cancelled,
            $transfer->fresh()->status,
        );
        $this->assertAccountBalances($request, '5000.00', '1000.00');
    }

    public function test_transfer_from_another_active_workspace_cannot_be_changed(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        [$currentSource, $currentDestination] = $this->accountPair($currentWorkspace);
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        [$otherSource, $otherDestination] = $this->accountPair($otherWorkspace);
        $otherTransfer = $this->createTransfer(
            $otherWorkspace,
            $otherSource,
            $otherDestination,
        );

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->put(route('transfers.update', $otherTransfer), [
                ...$this->validTransferData($currentSource, $currentDestination),
                'description' => 'Tentativa indevida',
            ])
            ->assertNotFound();

        $this->assertNotSame(
            'Tentativa indevida',
            $otherTransfer->fresh()->description,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTransfer(
        Workspace $workspace,
        FinancialAccount $source,
        FinancialAccount $destination,
        array $overrides = [],
    ): FinancialTransaction {
        return app(TransferService::class)->create($workspace, [
            ...$this->validTransferData($source, $destination),
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validTransferData(
        FinancialAccount $source,
        FinancialAccount $destination,
    ): array {
        return [
            'transaction_date' => '2026-09-20',
            'description' => 'Transferência entre contas',
            'amount' => '100.00',
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ];
    }

    /**
     * @return array{FinancialAccount, FinancialAccount}
     */
    private function accountPair(Workspace $workspace): array
    {
        return [
            FinancialAccount::factory()->for($workspace)->create(),
            FinancialAccount::factory()->for($workspace)->create(),
        ];
    }

    private function assertAccountBalances(
        self $request,
        string $sourceBalance,
        string $destinationBalance,
    ): void {
        $request->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.name', 'A Origem')
                ->where('accounts.0.current_balance', $sourceBalance)
                ->where('accounts.1.name', 'B Destino')
                ->where('accounts.1.current_balance', $destinationBalance)
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
