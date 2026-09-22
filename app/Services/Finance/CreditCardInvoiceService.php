<?php

namespace App\Services\Finance;

use App\Enums\AccountMovementType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardInvoicePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditCardInvoiceService
{
    public function close(CreditCardInvoice $invoice, ?string $statementAmount = null): CreditCardInvoice
    {
        if ($invoice->status === CreditCardInvoiceStatus::Paid) {
            throw ValidationException::withMessages([
                'statement_amount' => 'Uma fatura já paga não pode ser fechada novamente.',
            ]);
        }

        $invoice->update([
            'statement_amount' => $statementAmount,
            'status' => $invoice->status === CreditCardInvoiceStatus::Partial
                ? CreditCardInvoiceStatus::Partial
                : CreditCardInvoiceStatus::Closed,
        ]);

        return $invoice->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function pay(
        CreditCardInvoice $invoice,
        array $data,
        bool $allowOpen = false,
    ): CreditCardInvoice {
        return DB::transaction(function () use ($invoice, $data, $allowOpen): CreditCardInvoice {
            $locked = CreditCardInvoice::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === CreditCardInvoiceStatus::Open) {
                if (! $allowOpen) {
                    throw ValidationException::withMessages([
                        'amount' => 'Feche a fatura antes de registrar o pagamento.',
                    ]);
                }

                $locked->update([
                    'status' => CreditCardInvoiceStatus::Closed,
                ]);
                $locked->refresh();
            }

            $totalCents = $this->moneyToCents(
                (string) ($locked->statement_amount ?? $locked->calculated_amount),
            );
            $paidCents = $this->moneyToCents((string) $locked->paid_amount);
            $paymentCents = $this->moneyToCents((string) $data['amount']);
            $outstandingCents = max(0, $totalCents - $paidCents);

            if ($paymentCents <= 0 || $paymentCents > $outstandingCents) {
                throw ValidationException::withMessages([
                    'amount' => 'O pagamento deve ser maior que zero e não pode ultrapassar o saldo da fatura.',
                ]);
            }

            $payment = $locked->payments()->create([
                'workspace_id' => $locked->workspace_id,
                'financial_account_id' => $data['financial_account_id'],
                'paid_on' => $data['paid_on'],
                'amount' => $this->centsToMoney($paymentCents),
                'payment_method' => $data['payment_method'],
                'notes' => $data['notes'] ?? null,
            ]);

            $payment->movement()->create([
                'workspace_id' => $locked->workspace_id,
                'financial_account_id' => $data['financial_account_id'],
                'occurred_on' => $data['paid_on'],
                'description' => 'Pagamento fatura '.$locked->creditCard()->value('name'),
                'amount' => '-'.$this->centsToMoney($paymentCents),
                'type' => AccountMovementType::CardPayment,
                'is_reconciled' => false,
            ]);

            $newPaidCents = $paidCents + $paymentCents;
            $isPaid = $newPaidCents >= $totalCents;

            $locked->update([
                'paid_amount' => $this->centsToMoney($newPaidCents),
                'paid_at' => $isPaid ? $data['paid_on'] : null,
                'status' => $isPaid
                    ? CreditCardInvoiceStatus::Paid
                    : CreditCardInvoiceStatus::Partial,
            ]);

            if ($isPaid) {
                $locked->installments()
                    ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
                    ->update([
                        'status' => TransactionInstallmentStatus::Paid->value,
                        'paid_at' => $data['paid_on'],
                    ]);
            }

            return $locked->refresh();
        });
    }

    public function outstandingAmount(CreditCardInvoice $invoice): string
    {
        return $this->centsToMoney($this->outstandingCents($invoice));
    }

    public function totalAmount(CreditCardInvoice $invoice): string
    {
        return (string) ($invoice->statement_amount ?? $invoice->calculated_amount);
    }

    public function findCompatibleUnreconciledPayment(
        CreditCardInvoice $invoice,
        int $accountId,
        string $amount,
        string $paidOn,
    ): ?CreditCardInvoicePayment {
        $targetCents = $this->moneyToCents($amount);
        $paidOnDate = $paidOn;

        return $invoice->payments()
            ->with('movement')
            ->where('financial_account_id', $accountId)
            ->get()
            ->filter(function (CreditCardInvoicePayment $payment) use ($targetCents): bool {
                $movement = $payment->movement;

                return $movement !== null
                    && ! $movement->is_reconciled
                    && $movement->bankStatementEntry()->doesntExist()
                    && $this->moneyToCents((string) $payment->amount) === $targetCents;
            })
            ->sortBy(fn (CreditCardInvoicePayment $payment): int => (int) abs(
                $payment->paid_on->diffInDays($paidOnDate, false),
            ))
            ->first();
    }

    public function outstandingCents(CreditCardInvoice $invoice): int
    {
        $total = $this->moneyToCents($this->totalAmount($invoice));
        $paid = $this->moneyToCents((string) $invoice->paid_amount);

        return max(0, $total - $paid);
    }

    private function moneyToCents(string $amount): int
    {
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');

        return ((int) $whole * 100) + (int) $decimal;
    }

    private function centsToMoney(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
