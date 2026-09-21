<?php

namespace App\Services\Reconciliation;

use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BankReconciliationService
{
    public function reconcile(
        Workspace $workspace,
        BankStatementEntry $entry,
        AccountMovement $movement,
        User $user,
    ): BankStatementEntry {
        return DB::transaction(function () use (
            $workspace,
            $entry,
            $movement,
            $user,
        ): BankStatementEntry {
            $lockedEntry = BankStatementEntry::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedMovement = AccountMovement::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($movement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedEntry->is_reconciled || $lockedEntry->account_movement_id !== null) {
                throw ValidationException::withMessages([
                    'account_movement_id' => 'Este movimento bancário já foi conciliado.',
                ]);
            }

            if (
                $lockedMovement->is_reconciled
                || BankStatementEntry::query()
                    ->where('account_movement_id', $lockedMovement->id)
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'account_movement_id' => 'O lançamento selecionado já foi conciliado.',
                ]);
            }

            if ($lockedEntry->financial_account_id !== $lockedMovement->financial_account_id) {
                throw ValidationException::withMessages([
                    'account_movement_id' => 'O movimento bancário e o lançamento devem pertencer à mesma conta.',
                ]);
            }

            if ($this->moneyToCents($lockedEntry->amount) !== $this->moneyToCents($lockedMovement->amount)) {
                throw ValidationException::withMessages([
                    'account_movement_id' => 'O valor e o sentido do lançamento devem coincidir com o movimento bancário.',
                ]);
            }

            $lockedEntry->update([
                'account_movement_id' => $lockedMovement->id,
                'reconciled_by' => $user->id,
                'reconciled_at' => now(),
                'is_reconciled' => true,
            ]);
            $lockedMovement->update(['is_reconciled' => true]);

            return $lockedEntry->refresh();
        });
    }

    public function undo(
        Workspace $workspace,
        BankStatementEntry $entry,
    ): BankStatementEntry {
        return DB::transaction(function () use ($workspace, $entry): BankStatementEntry {
            $lockedEntry = BankStatementEntry::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            $movementId = $lockedEntry->account_movement_id;

            $lockedEntry->update([
                'account_movement_id' => null,
                'reconciled_by' => null,
                'reconciled_at' => null,
                'is_reconciled' => false,
            ]);

            if ($movementId !== null) {
                AccountMovement::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($movementId)
                    ->lockForUpdate()
                    ->first()?->update(['is_reconciled' => false]);
            }

            return $lockedEntry->refresh();
        });
    }

    private function moneyToCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }
}
