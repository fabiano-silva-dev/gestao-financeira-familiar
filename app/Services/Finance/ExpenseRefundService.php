<?php

namespace App\Services\Finance;

use App\Enums\AccountMovementType;
use App\Enums\ExpenseRefundDestination;
use App\Enums\ExpenseRefundOrigin;
use App\Enums\ExpenseRefundStatus;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseRefund;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExpenseRefundService
{
    public function __construct(
        private readonly CreditCardInvoiceService $invoiceService,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function register(
        Workspace $workspace,
        FinancialTransaction $transaction,
        User $user,
        array $data,
        ExpenseRefundOrigin $origin = ExpenseRefundOrigin::Manual,
    ): ExpenseRefund {
        abort_unless($transaction->workspace_id === $workspace->id, 404);

        return DB::transaction(function () use (
            $workspace,
            $transaction,
            $user,
            $data,
            $origin,
        ): ExpenseRefund {
            $locked = FinancialTransaction::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRefundableTransaction($locked);

            $amountCents = $this->moneyToCents((string) $data['amount']);
            $remainingCents = $this->refundableCents($locked);

            if ($amountCents <= 0 || $amountCents > $remainingCents) {
                throw ValidationException::withMessages([
                    'amount' => 'O reembolso deve ser maior que zero e não pode ultrapassar o saldo ainda reembolsável da compra.',
                ]);
            }

            $destination = ExpenseRefundDestination::from(
                (string) $data['destination_type'],
            );
            $attributes = [
                'workspace_id' => $workspace->id,
                'financial_transaction_id' => $locked->id,
                'amount' => $this->centsToMoney($amountCents),
                'refunded_on' => $data['refunded_on'],
                'destination_type' => $destination,
                'status' => ExpenseRefundStatus::Confirmed,
                'origin' => $origin,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'linked_by' => null,
                'linked_at' => null,
            ];

            if ($destination === ExpenseRefundDestination::Account) {
                $account = $workspace->financialAccounts()
                    ->find($data['destination_account_id'] ?? null);

                if ($account === null) {
                    throw ValidationException::withMessages([
                        'destination_account_id' => 'Selecione uma conta de destino válida.',
                    ]);
                }

                $refund = ExpenseRefund::query()->create([
                    ...$attributes,
                    'destination_account_id' => $account->id,
                    'credit_card_id' => null,
                    'credit_card_invoice_id' => null,
                ]);

                $refund->movement()->create([
                    'workspace_id' => $workspace->id,
                    'financial_transaction_id' => null,
                    'credit_card_invoice_payment_id' => null,
                    'financial_account_id' => $account->id,
                    'occurred_on' => $data['refunded_on'],
                    'description' => 'Reembolso · '.$locked->description,
                    'amount' => $this->centsToMoney($amountCents),
                    'type' => AccountMovementType::Refund,
                    'is_reconciled' => false,
                ]);

                return $refund->refresh();
            }

            if ($locked->credit_card_id === null) {
                throw ValidationException::withMessages([
                    'destination_type' => 'Somente uma compra feita no cartão pode receber estorno diretamente no cartão.',
                ]);
            }

            $invoice = $workspace->creditCardInvoices()
                ->find($data['credit_card_invoice_id'] ?? null);

            if (! $invoice instanceof CreditCardInvoice) {
                throw ValidationException::withMessages([
                    'credit_card_invoice_id' => 'Selecione uma fatura válida para o estorno.',
                ]);
            }

            if ($invoice->credit_card_id !== $locked->credit_card_id) {
                throw ValidationException::withMessages([
                    'credit_card_invoice_id' => 'A fatura deve pertencer ao mesmo cartão da compra original.',
                ]);
            }

            $invoiceRefundable = $this->invoiceRefundableCents($invoice);

            if ($amountCents > $invoiceRefundable) {
                throw ValidationException::withMessages([
                    'amount' => 'O estorno não pode ultrapassar o valor ainda ajustável desta fatura.',
                ]);
            }

            $refund = ExpenseRefund::query()->create([
                ...$attributes,
                'destination_account_id' => null,
                'credit_card_id' => $locked->credit_card_id,
                'credit_card_invoice_id' => $invoice->id,
                'linked_by' => $user->id,
                'linked_at' => now(),
            ]);

            if ($invoice->statement_amount !== null) {
                $statementCents = $this->moneyToCents(
                    (string) $invoice->statement_amount,
                );
                $newRefundCents = $this->invoiceService->refundCents($invoice);
                $previousRefundCents = max(0, $newRefundCents - $amountCents);
                $includedCreditCents = $this->statementCreditCents($invoice);
                $previousUnreflected = max(
                    0,
                    $previousRefundCents - $includedCreditCents,
                );
                $newUnreflected = max(
                    0,
                    $newRefundCents - $includedCreditCents,
                );
                $adjustmentCents = max(
                    0,
                    $newUnreflected - $previousUnreflected,
                );

                if ($adjustmentCents > 0) {
                    $invoice->update([
                        'statement_amount' => $this->centsToMoney(
                            max(0, $statementCents - $adjustmentCents),
                        ),
                    ]);
                }
            }

            return $refund->refresh();
        });
    }

    public function markLinked(ExpenseRefund $refund, User $user): ExpenseRefund
    {
        $refund->update([
            'linked_by' => $user->id,
            'linked_at' => now(),
        ]);

        return $refund->refresh();
    }

    public function refundedCents(FinancialTransaction $transaction): int
    {
        $amounts = $transaction->refunds()
            ->where('status', ExpenseRefundStatus::Confirmed->value)
            ->pluck('amount')
            ->all();

        return $this->sumMoney($amounts);
    }

    public function refundableCents(FinancialTransaction $transaction): int
    {
        $reserved = $transaction->refunds()
            ->where('status', '!=', ExpenseRefundStatus::Cancelled->value)
            ->pluck('amount')
            ->all();

        return max(
            0,
            $this->moneyToCents((string) $transaction->amount)
                - $this->sumMoney($reserved),
        );
    }

    public function netAmountCents(FinancialTransaction $transaction): int
    {
        return max(
            0,
            $this->moneyToCents((string) $transaction->amount)
                - $this->refundedCents($transaction),
        );
    }

    public function allocatedRefundCentsForInstallment(
        TransactionInstallment $installment,
    ): int {
        $transaction = $installment->transaction;

        if (! $transaction instanceof FinancialTransaction) {
            return 0;
        }

        $refundCents = $this->refundedCents($transaction);
        $transactionCents = $this->moneyToCents((string) $transaction->amount);

        if ($refundCents <= 0 || $transactionCents <= 0) {
            return 0;
        }

        $installments = $transaction->installments()
            ->orderBy('installment_number')
            ->get(['id', 'amount', 'installment_number']);
        $remaining = $refundCents;
        $lastId = $installments->last()?->id;

        foreach ($installments as $item) {
            $itemCents = $this->moneyToCents((string) $item->amount);
            $allocated = $item->id === $lastId
                ? $remaining
                : min(
                    $remaining,
                    intdiv($refundCents * $itemCents, $transactionCents),
                );

            if ($item->id === $installment->id) {
                return min($itemCents, $allocated);
            }

            $remaining = max(0, $remaining - $allocated);
        }

        return 0;
    }

    /**
     * @return array{
     *     original_amount: string,
     *     refunded_amount: string,
     *     refundable_amount: string,
     *     net_amount: string,
     *     refund_status: string,
     *     refund_status_label: string
     * }
     */
    public function summary(FinancialTransaction $transaction): array
    {
        $original = $this->moneyToCents((string) $transaction->amount);
        $refunded = $this->refundedCents($transaction);
        $net = max(0, $original - $refunded);
        $status = match (true) {
            $refunded <= 0 => 'none',
            $net === 0 => 'refunded',
            default => 'partial',
        };

        return [
            'original_amount' => $this->centsToMoney($original),
            'refunded_amount' => $this->centsToMoney($refunded),
            'refundable_amount' => $this->centsToMoney($this->refundableCents($transaction)),
            'net_amount' => $this->centsToMoney($net),
            'refund_status' => $status,
            'refund_status_label' => match ($status) {
                'refunded' => 'Reembolsada',
                'partial' => 'Reembolso parcial',
                default => 'Sem reembolso',
            },
        ];
    }

    private function assertRefundableTransaction(FinancialTransaction $transaction): void
    {
        if ($transaction->type !== FinancialTransactionType::Expense) {
            throw ValidationException::withMessages([
                'entry' => 'Somente compras ou despesas podem receber reembolso.',
            ]);
        }

        if ($transaction->status === FinancialTransactionStatus::Cancelled) {
            throw ValidationException::withMessages([
                'entry' => 'Uma despesa cancelada não pode receber reembolso.',
            ]);
        }
    }

    private function statementCreditCents(CreditCardInvoice $invoice): int
    {
        return $invoice->statementEntries()
            ->where('amount', '<', 0)
            ->pluck('amount')
            ->reduce(
                fn (int $total, mixed $amount): int =>
                    $total + abs($this->moneyToCents((string) $amount)),
                0,
            );
    }

    private function invoiceRefundableCents(CreditCardInvoice $invoice): int
    {
        return $this->moneyToCents(
            $this->invoiceService->totalAmount($invoice),
        );
    }

    /** @param array<int, mixed> $values */
    private function sumMoney(array $values): int
    {
        return array_reduce(
            $values,
            fn (int $total, mixed $value): int =>
                $total + $this->moneyToCents((string) $value),
            0,
        );
    }

    private function moneyToCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $cents = ((int) $whole * 100)
            + (int) str_pad(substr($decimal, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
