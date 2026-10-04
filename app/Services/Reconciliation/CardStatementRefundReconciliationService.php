<?php

namespace App\Services\Reconciliation;

use App\Enums\ExpenseRefundDestination;
use App\Enums\ExpenseRefundOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Models\CardStatementEntry;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseRefund;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\ExpenseRefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CardStatementRefundReconciliationService
{
    public function __construct(
        private readonly ExpenseRefundService $refundService,
        private readonly ExpenseRefundSuggestionService $refundSuggestions,
        private readonly ImportedMovementInterpreter $interpreter,
    ) {}

    public function reconcile(
        Workspace $workspace,
        CreditCardInvoice $invoice,
        CardStatementEntry $entry,
        FinancialTransaction $transaction,
        User $user,
    ): CardStatementEntry {
        return DB::transaction(function () use (
            $workspace,
            $invoice,
            $entry,
            $transaction,
            $user,
        ): CardStatementEntry {
            $lockedEntry = CardStatementEntry::query()
                ->where('workspace_id', $workspace->id)
                ->where('credit_card_invoice_id', $invoice->id)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedTransaction = FinancialTransaction::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedEntry->is_reconciled
                || $lockedEntry->is_ignored
                || $lockedEntry->is_payment
                || $this->interpreter->moneyToCents($lockedEntry->amount) >= 0
            ) {
                throw ValidationException::withMessages([
                    'financial_transaction_id' => 'Somente um crédito pendente da fatura pode ser vinculado como reembolso.',
                ]);
            }

            if (
                $lockedTransaction->type !== FinancialTransactionType::Expense
                || $lockedTransaction->status === FinancialTransactionStatus::Cancelled
                || $lockedTransaction->credit_card_id !== $lockedEntry->credit_card_id
            ) {
                throw ValidationException::withMessages([
                    'financial_transaction_id' => 'Selecione uma compra do mesmo cartão.',
                ]);
            }

            $amountCents = abs($this->interpreter->moneyToCents($lockedEntry->amount));
            $refund = $this->refundSuggestions->matchingUnlinkedRefund(
                $lockedTransaction,
                $lockedEntry,
                $amountCents,
            );

            if (! $refund instanceof ExpenseRefund) {
                $refund = $this->refundService->register(
                    $workspace,
                    $lockedTransaction,
                    $user,
                    [
                        'amount' => $this->interpreter->unsignedAmount($lockedEntry->amount),
                        'refunded_on' => $lockedEntry->purchased_on->toDateString(),
                        'destination_type' => ExpenseRefundDestination::CreditCard->value,
                        'destination_account_id' => null,
                        'credit_card_invoice_id' => $invoice->id,
                        'notes' => 'Estorno conciliado com crédito da fatura: '.$lockedEntry->description,
                    ],
                    ExpenseRefundOrigin::CardStatement,
                );
            }

            $this->refundService->markLinked($refund, $user);
            $lockedEntry->update([
                'expense_refund_id' => $refund->id,
                'transaction_installment_id' => null,
                'is_reconciled' => true,
                'reconciled_by' => $user->id,
                'reconciled_at' => now(),
            ]);

            return $lockedEntry->refresh();
        });
    }
}
