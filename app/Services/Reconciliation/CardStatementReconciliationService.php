<?php

namespace App\Services\Reconciliation;

use App\Enums\FinancialTransactionStatus;
use App\Models\CardStatementEntry;
use App\Models\CreditCardInvoice;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CardStatementReconciliationService
{
    public function __construct(
        private readonly FinancialEntryService $entryService,
    ) {}

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

    public function reconcilePlannedRecurrence(
        Workspace $workspace,
        CreditCardInvoice $invoice,
        CardStatementEntry $entry,
        FinancialTransaction $transaction,
        User $user,
    ): CardStatementEntry {
        return DB::transaction(function () use ($workspace, $invoice, $entry, $transaction, $user): CardStatementEntry {
            $lockedEntry = CardStatementEntry::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedTransaction = FinancialTransaction::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedEntry->credit_card_invoice_id !== $invoice->id
                || $lockedEntry->credit_card_id !== $invoice->credit_card_id
                || $lockedTransaction->credit_card_id !== $invoice->credit_card_id
                || $lockedTransaction->financial_recurrence_id === null
                || $lockedTransaction->status !== FinancialTransactionStatus::Planned
            ) {
                throw ValidationException::withMessages([
                    'recurrence_transaction_id' => 'A previsão recorrente não é compatível com esta linha da fatura.',
                ]);
            }

            if ($lockedEntry->is_reconciled) {
                throw ValidationException::withMessages([
                    'recurrence_transaction_id' => 'Esta linha da fatura já foi conciliada.',
                ]);
            }

            $expectedAmount = $lockedTransaction->amount;
            $notes = $lockedTransaction->notes;
            if ($this->moneyToCents($expectedAmount) !== $this->moneyToCents($lockedEntry->amount)) {
                $variation = 'Previsto: R$ '.str_replace('.', ',', $expectedAmount)
                    .' · realizado: R$ '.str_replace('.', ',', $lockedEntry->amount).'.';
                $notes = trim(($notes ? $notes."\n" : '').'Variação da recorrência na conciliação. '.$variation);
            }

            $confirmed = $this->entryService->update($lockedTransaction, [
                'type' => $lockedTransaction->type->value,
                'transaction_date' => $lockedEntry->purchased_on->toDateString(),
                'competence_date' => $lockedTransaction->competence_date?->toDateString()
                    ?? $lockedEntry->purchased_on->toDateString(),
                'description' => $lockedTransaction->description,
                'amount' => $lockedEntry->amount,
                'financial_account_id' => null,
                'credit_card_id' => $lockedTransaction->credit_card_id,
                'category_id' => $lockedTransaction->category_id,
                'family_member_id' => $lockedTransaction->family_member_id,
                'payment_method' => $lockedTransaction->payment_method?->value,
                'payee_name' => $lockedTransaction->payee_name,
                'payment_instructions' => $lockedTransaction->payment_instructions,
                'due_date' => null,
                'settled_on' => null,
                'status' => FinancialTransactionStatus::Confirmed->value,
                'installment_count' => 1,
                'notes' => $notes,
            ]);

            $installment = $confirmed->installments()
                ->where('credit_card_invoice_id', $invoice->id)
                ->first();

            if ($installment === null) {
                throw ValidationException::withMessages([
                    'recurrence_transaction_id' => 'A cobrança real pertence a outro ciclo de fatura. Confira a data prevista.',
                ]);
            }

            return $this->reconcile($workspace, $invoice, $lockedEntry, $installment, $user);
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
