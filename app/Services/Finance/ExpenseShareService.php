<?php

namespace App\Services\Finance;

use App\Enums\AccountMovementType;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Models\ExpenseShare;
use App\Models\ExpenseShareReceipt;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExpenseShareService
{
    public function __construct(
        private readonly ExpenseRefundService $refundService,
    ) {}

    /** @param array<string, mixed> $data */
    public function registerReceipt(
        Workspace $workspace,
        FinancialTransaction $transaction,
        User $user,
        array $data,
    ): ExpenseShareReceipt {
        abort_unless($transaction->workspace_id === $workspace->id, 404);

        return DB::transaction(function () use (
            $workspace,
            $transaction,
            $user,
            $data,
        ): ExpenseShareReceipt {
            $locked = FinancialTransaction::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertShareableTransaction($locked);

            $account = $workspace->financialAccounts()
                ->whereKey((int) $data['financial_account_id'])
                ->firstOrFail();
            $receiptCents = $this->moneyToCents((string) $data['amount']);

            if ($receiptCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'O recebimento do rateio deve ser maior que zero.',
                ]);
            }

            $share = ExpenseShare::query()
                ->where('workspace_id', $workspace->id)
                ->where('financial_transaction_id', $locked->id)
                ->lockForUpdate()
                ->first();
            $receivedBefore = $share instanceof ExpenseShare
                ? $this->receivedCents($share)
                : 0;
            $maxExpected = max(
                0,
                $this->moneyToCents((string) $locked->amount)
                    - $this->refundService->refundedCents($locked),
            );
            $expectedInput = isset($data['expected_shared_amount'])
                && $data['expected_shared_amount'] !== null
                && $data['expected_shared_amount'] !== ''
                    ? $this->moneyToCents((string) $data['expected_shared_amount'])
                    : null;

            if (! $share instanceof ExpenseShare) {
                if ($expectedInput === null) {
                    throw ValidationException::withMessages([
                        'expected_shared_amount' => 'Informe quanto desta despesa deve ser pago por terceiros.',
                    ]);
                }

                if (
                    $expectedInput <= 0
                    || $expectedInput > $maxExpected
                    || $expectedInput < $receiptCents
                ) {
                    throw ValidationException::withMessages([
                        'expected_shared_amount' => 'O valor esperado de terceiros deve cobrir este recebimento e não pode ultrapassar a parte disponível da despesa.',
                    ]);
                }

                $share = ExpenseShare::query()->create([
                    'workspace_id' => $workspace->id,
                    'financial_transaction_id' => $locked->id,
                    'expected_amount' => $this->centsToMoney($expectedInput),
                    'created_by' => $user->id,
                ]);
            }

            $expected = $expectedInput
                ?? $this->moneyToCents((string) $share->expected_amount);

            if (
                $expected <= 0
                || $expected > $maxExpected
                || $expected < ($receivedBefore + $receiptCents)
            ) {
                throw ValidationException::withMessages([
                    'expected_shared_amount' => 'O valor esperado de terceiros deve cobrir os recebimentos do rateio e não pode ultrapassar a parte disponível da despesa.',
                ]);
            }

            if (
                $expected !== $this->moneyToCents((string) $share->expected_amount)
            ) {
                $share->update([
                    'expected_amount' => $this->centsToMoney($expected),
                ]);
            }

            $receipt = $share->receipts()->create([
                'workspace_id' => $workspace->id,
                'amount' => $this->centsToMoney($receiptCents),
                'received_on' => $data['received_on'],
                'financial_account_id' => $account->id,
                'origin' => 'bank_reconciliation',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $receipt->movement()->create([
                'workspace_id' => $workspace->id,
                'financial_transaction_id' => null,
                'financial_account_id' => $account->id,
                'occurred_on' => $data['received_on'],
                'description' => 'Rateio: '.$locked->description,
                'amount' => $this->centsToMoney($receiptCents),
                'type' => AccountMovementType::SharedExpenseReceipt,
                'is_reconciled' => false,
            ]);

            return $receipt->refresh();
        });
    }

    public function markLinked(
        ExpenseShareReceipt $receipt,
        User $user,
    ): ExpenseShareReceipt {
        $receipt->update([
            'linked_by' => $user->id,
            'linked_at' => now(),
        ]);

        return $receipt->refresh();
    }

    public function receivedCents(
        FinancialTransaction|ExpenseShare $subject,
    ): int {
        $share = $subject instanceof FinancialTransaction
            ? $subject->expenseShare
            : $subject;

        if (! $share instanceof ExpenseShare) {
            return 0;
        }

        $amounts = $share->relationLoaded('receipts')
            ? $share->receipts->pluck('amount')->all()
            : $share->receipts()->pluck('amount')->all();

        return $this->sumMoney($amounts);
    }

    public function remainingExpectedCents(ExpenseShare $share): int
    {
        return max(
            0,
            $this->moneyToCents((string) $share->expected_amount)
                - $this->receivedCents($share),
        );
    }

    public function netAmountCents(FinancialTransaction $transaction): int
    {
        return max(
            0,
            $this->refundService->netAmountCents($transaction)
                - $this->receivedCents($transaction),
        );
    }

    public function allocatedReceiptCentsForInstallment(
        TransactionInstallment $installment,
    ): int {
        $transaction = $installment->transaction;

        if (! $transaction instanceof FinancialTransaction) {
            return 0;
        }

        $receivedCents = $this->receivedCents($transaction);
        $transactionCents = $this->moneyToCents((string) $transaction->amount);

        if ($receivedCents <= 0 || $transactionCents <= 0) {
            return 0;
        }

        $installments = $transaction->installments()
            ->orderBy('installment_number')
            ->get(['id', 'amount', 'installment_number']);
        $remaining = $receivedCents;
        $lastId = $installments->last()?->id;

        foreach ($installments as $item) {
            $itemCents = $this->moneyToCents((string) $item->amount);
            $allocated = $item->id === $lastId
                ? $remaining
                : min(
                    $remaining,
                    intdiv($receivedCents * $itemCents, $transactionCents),
                );

            if ($item->id === $installment->id) {
                return min($itemCents, $allocated);
            }

            $remaining = max(0, $remaining - $allocated);
        }

        return 0;
    }

    /** @return array<string, string> */
    public function summary(FinancialTransaction $transaction): array
    {
        $share = $transaction->expenseShare;
        $received = $this->receivedCents($transaction);
        $expected = $share instanceof ExpenseShare
            ? $this->moneyToCents((string) $share->expected_amount)
            : 0;
        $remaining = max(0, $expected - $received);
        [$status, $label] = match (true) {
            ! $share instanceof ExpenseShare => ['none', 'Sem rateio'],
            $remaining === 0 => ['closed', 'Rateio fechado'],
            $received > 0 => ['partial', 'Rateio parcial'],
            default => ['open', 'Rateio aberto'],
        };

        return [
            'expected_shared_amount' => $this->centsToMoney($expected),
            'shared_amount' => $this->centsToMoney($received),
            'remaining_shared_amount' => $this->centsToMoney($remaining),
            'share_status' => $status,
            'share_status_label' => $label,
            'net_amount' => $this->centsToMoney(
                $this->netAmountCents($transaction),
            ),
        ];
    }

    private function assertShareableTransaction(
        FinancialTransaction $transaction,
    ): void {
        if (
            $transaction->type !== FinancialTransactionType::Expense
            || $transaction->status === FinancialTransactionStatus::Cancelled
        ) {
            throw ValidationException::withMessages([
                'financial_transaction_id' => 'O rateio deve estar vinculado a uma despesa ativa.',
            ]);
        }
    }

    /** @param array<int, mixed> $amounts */
    private function sumMoney(array $amounts): int
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $this->moneyToCents((string) $amount);
        }

        return $total;
    }

    private function moneyToCents(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');

        if ($negative) {
            $amount = substr($amount, 1);
        }

        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $decimal;

        return $negative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        return sprintf(
            '%d.%02d',
            intdiv($cents, 100),
            abs($cents % 100),
        );
    }
}
