<?php

namespace App\Services\Finance;

use App\Enums\AccountMovementType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\ExpenseRefundStatus;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardInvoicePayment;
use Illuminate\Support\Collection;
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
            }

            $paymentCents = $this->moneyToCents((string) $data['amount']);
            $outstandingCents = $this->outstandingCents($locked);

            if ($paymentCents <= 0 || $paymentCents > $outstandingCents) {
                throw ValidationException::withMessages([
                    'amount' => 'O pagamento deve ser maior que zero e não pode ultrapassar o saldo da fatura.',
                ]);
            }

            $payment = $locked->payments()->create([
                'workspace_id' => $locked->workspace_id,
                'credit_card_id' => $locked->credit_card_id,
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
                'description' => 'Pagamento cartão '.$locked->creditCard()->value('name'),
                'amount' => '-'.$this->centsToMoney($paymentCents),
                'type' => AccountMovementType::CardPayment,
                'is_reconciled' => false,
            ]);

            $this->applyPaymentToInvoice(
                $locked,
                $paymentCents,
                (string) $data['paid_on'],
            );

            return $locked->refresh();
        });
    }

    /**
     * Registra uma saída destinada ao cartão mesmo quando a fatura ainda
     * não existe ou ainda não foi identificada.
     *
     * @param array<string, mixed> $data
     */
    public function createPendingPayment(
        CreditCard $card,
        array $data,
    ): CreditCardInvoicePayment {
        return DB::transaction(function () use ($card, $data): CreditCardInvoicePayment {
            $paymentCents = $this->moneyToCents((string) $data['amount']);

            if ($paymentCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'O pagamento do cartão deve ser maior que zero.',
                ]);
            }

            $payment = CreditCardInvoicePayment::query()->create([
                'workspace_id' => $card->workspace_id,
                'credit_card_id' => $card->id,
                'credit_card_invoice_id' => null,
                'financial_account_id' => $data['financial_account_id'],
                'paid_on' => $data['paid_on'],
                'amount' => $this->centsToMoney($paymentCents),
                'payment_method' => $data['payment_method'],
                'notes' => $data['notes'] ?? null,
            ]);

            $payment->movement()->create([
                'workspace_id' => $card->workspace_id,
                'financial_account_id' => $data['financial_account_id'],
                'occurred_on' => $data['paid_on'],
                'description' => 'Pagamento cartão '.$card->name,
                'amount' => '-'.$this->centsToMoney($paymentCents),
                'type' => AccountMovementType::CardPayment,
                'is_reconciled' => false,
            ]);

            return $payment->refresh();
        });
    }

    public function linkPendingPayment(
        CreditCardInvoice $invoice,
        CreditCardInvoicePayment $payment,
    ): CreditCardInvoice {
        return DB::transaction(function () use ($invoice, $payment): CreditCardInvoice {
            $lockedInvoice = CreditCardInvoice::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPayment = CreditCardInvoicePayment::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->credit_card_invoice_id === $lockedInvoice->id) {
                return $lockedInvoice->refresh();
            }

            if ($lockedPayment->credit_card_invoice_id !== null) {
                throw ValidationException::withMessages([
                    'payment' => 'Este pagamento já está vinculado a outra fatura.',
                ]);
            }

            if ($lockedPayment->credit_card_id !== $lockedInvoice->credit_card_id) {
                throw ValidationException::withMessages([
                    'payment' => 'O pagamento e a fatura devem pertencer ao mesmo cartão.',
                ]);
            }

            $paymentCents = $this->moneyToCents((string) $lockedPayment->amount);
            $outstandingCents = $this->outstandingCents($lockedInvoice);

            if ($paymentCents <= 0 || $paymentCents > $outstandingCents) {
                throw ValidationException::withMessages([
                    'payment' => 'O valor do pagamento não é compatível com o saldo em aberto desta fatura.',
                ]);
            }

            $lockedPayment->update([
                'credit_card_invoice_id' => $lockedInvoice->id,
            ]);
            $this->applyPaymentToInvoice(
                $lockedInvoice,
                $paymentCents,
                $lockedPayment->paid_on->toDateString(),
            );

            return $lockedInvoice->refresh();
        });
    }

    public function autoLinkPendingPayments(CreditCardInvoice $invoice): int
    {
        $invoice->refresh();

        if ($invoice->statement_amount === null) {
            return 0;
        }

        $linked = 0;
        $payments = CreditCardInvoicePayment::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('credit_card_id', $invoice->credit_card_id)
            ->whereNull('credit_card_invoice_id')
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();

        foreach ($payments as $payment) {
            $candidates = $this->compatibleImportedInvoices($payment);

            if (
                $candidates->count() !== 1
                || $candidates->first()?->id !== $invoice->id
            ) {
                continue;
            }

            $this->linkPendingPayment($invoice->refresh(), $payment);
            $linked++;
        }

        return $linked;
    }

    /** @return Collection<int, CreditCardInvoice> */
    private function compatibleImportedInvoices(
        CreditCardInvoicePayment $payment,
    ): Collection {
        $paymentCents = $this->moneyToCents((string) $payment->amount);

        return CreditCardInvoice::query()
            ->where('workspace_id', $payment->workspace_id)
            ->where('credit_card_id', $payment->credit_card_id)
            ->whereNotNull('statement_amount')
            ->whereIn('status', [
                CreditCardInvoiceStatus::Open->value,
                CreditCardInvoiceStatus::Closed->value,
                CreditCardInvoiceStatus::Partial->value,
            ])
            ->get()
            ->filter(function (CreditCardInvoice $candidate) use ($payment, $paymentCents): bool {
                $dateDistance = (int) abs(
                    $payment->paid_on->diffInDays($candidate->due_date, false),
                );

                return $dateDistance <= 45
                    && $paymentCents > 0
                    && $paymentCents <= $this->outstandingCents($candidate);
            })
            ->values();
    }

    private function applyPaymentToInvoice(
        CreditCardInvoice $invoice,
        int $paymentCents,
        string $paidOn,
    ): void {
        $totalCents = $this->moneyToCents(
            $this->totalAmount($invoice),
        );
        $paidCents = $this->moneyToCents((string) $invoice->paid_amount);
        $newPaidCents = $paidCents + $paymentCents;
        $isPaid = $totalCents > 0 && $newPaidCents >= $totalCents;

        $invoice->update([
            'paid_amount' => $this->centsToMoney($newPaidCents),
            'paid_at' => $isPaid ? $paidOn : null,
            'status' => $isPaid
                ? CreditCardInvoiceStatus::Paid
                : CreditCardInvoiceStatus::Partial,
        ]);

        if ($isPaid) {
            $invoice->installments()
                ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
                ->update([
                    'status' => TransactionInstallmentStatus::Paid->value,
                    'paid_at' => $paidOn,
                ]);
        }
    }

    public function outstandingAmount(CreditCardInvoice $invoice): string
    {
        return $this->centsToMoney($this->outstandingCents($invoice));
    }

    public function grossTotalAmount(CreditCardInvoice $invoice): string
    {
        return (string) ($invoice->statement_amount ?? $invoice->calculated_amount);
    }

    public function grossTotalCents(CreditCardInvoice $invoice): int
    {
        return $this->moneyToCents($this->grossTotalAmount($invoice));
    }

    public function totalAmount(CreditCardInvoice $invoice): string
    {
        if ($invoice->statement_amount !== null) {
            return (string) $invoice->statement_amount;
        }

        return $this->centsToMoney(
            max(0, $this->grossTotalCents($invoice) - $this->refundCents($invoice)),
        );
    }

    public function refundCents(CreditCardInvoice $invoice): int
    {
        $refunds = $invoice->refunds()
            ->where('status', ExpenseRefundStatus::Confirmed->value)
            ->pluck('amount')
            ->all();

        return array_reduce(
            $refunds,
            fn (int $total, mixed $amount): int =>
                $total + $this->moneyToCents((string) $amount),
            0,
        );
    }

    public function refundAmount(CreditCardInvoice $invoice): string
    {
        return $this->centsToMoney($this->refundCents($invoice));
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
