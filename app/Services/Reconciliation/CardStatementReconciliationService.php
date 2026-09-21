<?php

namespace App\Services\Reconciliation;

use App\Models\CardStatementEntry;
use App\Models\CreditCardInvoice;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CardStatementReconciliationService
{
    public function reconcile(
        Workspace $workspace,
        CreditCardInvoice $invoice,
        CardStatementEntry $entry,
        TransactionInstallment $installment,
        User $user,
    ): CardStatementEntry {
        return DB::transaction(function () use (
            $workspace,
            $invoice,
            $entry,
            $installment,
            $user,
        ): CardStatementEntry {
            $lockedEntry = CardStatementEntry::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedInstallment = TransactionInstallment::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($installment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedEntry->credit_card_invoice_id !== $invoice->id
                || $lockedEntry->credit_card_id !== $invoice->credit_card_id
                || $lockedInstallment->credit_card_invoice_id !== $invoice->id
            ) {
                throw ValidationException::withMessages([
                    'transaction_installment_id' => 'A linha importada e a parcela devem pertencer à mesma fatura e ao mesmo cartão.',
                ]);
            }

            if (
                $lockedEntry->is_reconciled
                || $lockedEntry->transaction_installment_id !== null
            ) {
                throw ValidationException::withMessages([
                    'transaction_installment_id' => 'Esta linha da fatura já foi conciliada.',
                ]);
            }

            if (
                CardStatementEntry::query()
                    ->where('transaction_installment_id', $lockedInstallment->id)
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'transaction_installment_id' => 'A parcela selecionada já foi conciliada com outra linha da fatura.',
                ]);
            }

            if ($this->moneyToCents($lockedEntry->amount) !== $this->moneyToCents($lockedInstallment->amount)) {
                throw ValidationException::withMessages([
                    'transaction_installment_id' => 'O valor da parcela deve coincidir com o valor da linha importada.',
                ]);
            }

            if (
                $lockedEntry->installment_number !== null
                && $lockedEntry->installment_number !== $lockedInstallment->installment_number
            ) {
                throw ValidationException::withMessages([
                    'transaction_installment_id' => 'O número da parcela não coincide com a linha importada.',
                ]);
            }

            if (
                $lockedEntry->total_installments !== null
                && $lockedEntry->total_installments !== $lockedInstallment->total_installments
            ) {
                throw ValidationException::withMessages([
                    'transaction_installment_id' => 'O total de parcelas não coincide com a linha importada.',
                ]);
            }

            $lockedEntry->update([
                'transaction_installment_id' => $lockedInstallment->id,
                'reconciled_by' => $user->id,
                'reconciled_at' => now(),
                'is_reconciled' => true,
            ]);

            return $lockedEntry->refresh();
        });
    }

    public function undo(
        Workspace $workspace,
        CreditCardInvoice $invoice,
        CardStatementEntry $entry,
    ): CardStatementEntry {
        return DB::transaction(function () use ($workspace, $invoice, $entry): CardStatementEntry {
            $lockedEntry = CardStatementEntry::query()
                ->where('workspace_id', $workspace->id)
                ->where('credit_card_invoice_id', $invoice->id)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedEntry->update([
                'transaction_installment_id' => null,
                'reconciled_by' => null,
                'reconciled_at' => null,
                'is_reconciled' => false,
            ]);

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
