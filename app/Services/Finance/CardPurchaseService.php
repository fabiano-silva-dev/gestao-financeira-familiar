<?php

namespace App\Services\Finance;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CardPurchaseService
{
    public function sync(FinancialTransaction $transaction, int $installmentCount): void
    {
        if (! $this->isCardExpense($transaction)) {
            $this->clear($transaction);

            return;
        }

        $installmentCount = max(1, $installmentCount);
        $existingInstallments = $transaction->installments()
            ->with('invoice.payments')
            ->get();
        $oldInvoices = $existingInstallments
            ->pluck('invoice')
            ->filter()
            ->unique('id')
            ->values();

        $this->assertInvoicesEditable($oldInvoices);
        $transaction->installments()->delete();
        $this->recalculateInvoices($oldInvoices);

        $card = $transaction->creditCard()->firstOrFail();
        $purchaseDate = CarbonImmutable::parse($transaction->transaction_date);
        $amounts = $this->splitAmount((string) $transaction->amount, $installmentCount);
        $firstClosingMonth = $this->firstClosingMonth($card, $purchaseDate);

        foreach ($amounts as $index => $amount) {
            [$closingDate, $dueDate] = $this->cycleDates(
                $card,
                $firstClosingMonth->addMonths($index),
            );
            $invoice = $this->resolveInvoice($card, $closingDate, $dueDate);
            $status = $transaction->status === FinancialTransactionStatus::Cancelled
                ? TransactionInstallmentStatus::Cancelled
                : TransactionInstallmentStatus::Open;

            $transaction->installments()->create([
                'workspace_id' => $transaction->workspace_id,
                'credit_card_invoice_id' => $invoice->id,
                'installment_number' => $index + 1,
                'total_installments' => $installmentCount,
                'amount' => $amount,
                'competence_month' => $purchaseDate
                    ->startOfMonth()
                    ->addMonths($index)
                    ->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'expected_payment_date' => $dueDate->toDateString(),
                'status' => $status,
            ]);

            $this->recalculateInvoice($invoice);
        }
    }

    public function clear(FinancialTransaction $transaction): void
    {
        $installments = $transaction->installments()
            ->with('invoice.payments')
            ->get();

        if ($installments->isEmpty()) {
            return;
        }

        $invoices = $installments
            ->pluck('invoice')
            ->filter()
            ->unique('id')
            ->values();

        $this->assertInvoicesEditable($invoices);
        $transaction->installments()->delete();
        $this->recalculateInvoices($invoices);
    }

    public function syncStatus(FinancialTransaction $transaction): void
    {
        if (! $this->isCardExpense($transaction)) {
            return;
        }

        $installments = $transaction->installments()
            ->with('invoice.payments')
            ->get();

        if ($installments->isEmpty()) {
            if ($transaction->status === FinancialTransactionStatus::Confirmed) {
                $this->sync($transaction, 1);
            }

            return;
        }

        $invoices = $installments
            ->pluck('invoice')
            ->filter()
            ->unique('id')
            ->values();

        $this->assertInvoicesEditable($invoices);
        $status = $transaction->status === FinancialTransactionStatus::Cancelled
            ? TransactionInstallmentStatus::Cancelled
            : TransactionInstallmentStatus::Open;

        $transaction->installments()->update([
            'status' => $status->value,
            'paid_at' => null,
        ]);
        $this->recalculateInvoices($invoices);
    }

    private function isCardExpense(FinancialTransaction $transaction): bool
    {
        return $transaction->type === FinancialTransactionType::Expense
            && $transaction->credit_card_id !== null;
    }

    /**
     * @param  Collection<int, CreditCardInvoice>  $invoices
     */
    private function assertInvoicesEditable(Collection $invoices): void
    {
        foreach ($invoices as $invoice) {
            if (
                $invoice->status !== CreditCardInvoiceStatus::Open
                || $invoice->payments->isNotEmpty()
            ) {
                throw ValidationException::withMessages([
                    'credit_card_id' => 'Esta compra já pertence a uma fatura fechada ou com pagamento e não pode alterar o parcelamento.',
                ]);
            }
        }
    }

    private function firstClosingMonth(
        CreditCard $card,
        CarbonImmutable $purchaseDate,
    ): CarbonImmutable {
        $month = $purchaseDate->startOfMonth();
        [$closingDate] = $this->cycleDates($card, $month);

        return $purchaseDate->greaterThan($closingDate)
            ? $month->addMonth()
            : $month;
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function cycleDates(
        CreditCard $card,
        CarbonImmutable $closingMonth,
    ): array {
        $closingDate = $this->dateInMonth($closingMonth, $card->closing_day);
        $dueDate = $this->dateInMonth($closingMonth, $card->due_day);

        if (! $dueDate->greaterThan($closingDate)) {
            $dueDate = $this->dateInMonth(
                $closingMonth->addMonth(),
                $card->due_day,
            );
        }

        return [$closingDate, $dueDate];
    }

    private function dateInMonth(CarbonImmutable $month, int $day): CarbonImmutable
    {
        $base = $month->startOfMonth();
        $safeDay = min($day, $base->daysInMonth);

        return $base->addDays($safeDay - 1);
    }

    private function resolveInvoice(
        CreditCard $card,
        CarbonImmutable $closingDate,
        CarbonImmutable $dueDate,
    ): CreditCardInvoice {
        $invoice = CreditCardInvoice::query()->firstOrCreate(
            [
                'workspace_id' => $card->workspace_id,
                'credit_card_id' => $card->id,
                'reference_month' => $dueDate->startOfMonth()->toDateString(),
            ],
            [
                'closing_date' => $closingDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'calculated_amount' => '0.00',
                'paid_amount' => '0.00',
                'status' => CreditCardInvoiceStatus::Open->value,
            ],
        );

        if ($invoice->status !== CreditCardInvoiceStatus::Open) {
            throw ValidationException::withMessages([
                'transaction_date' => 'A fatura calculada para esta compra já está fechada ou paga. Ajuste a data da compra ou reabra o ciclo antes de alterar lançamentos.',
            ]);
        }

        return $invoice;
    }

    /**
     * @param  Collection<int, CreditCardInvoice>  $invoices
     */
    private function recalculateInvoices(Collection $invoices): void
    {
        foreach ($invoices as $invoice) {
            if ($invoice->exists) {
                $this->recalculateInvoice($invoice->fresh());
            }
        }
    }

    private function recalculateInvoice(?CreditCardInvoice $invoice): void
    {
        if ($invoice === null) {
            return;
        }

        $amount = (string) $invoice->installments()
            ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
            ->sum('amount');

        if (
            $this->moneyToCents($amount) === 0
            && $invoice->status === CreditCardInvoiceStatus::Open
            && ! $invoice->installments()->exists()
            && ! $invoice->payments()->exists()
        ) {
            $invoice->delete();

            return;
        }

        $invoice->update(['calculated_amount' => $this->normalizeMoney($amount)]);
    }

    /**
     * @return array<int, string>
     */
    private function splitAmount(string $amount, int $count): array
    {
        $totalCents = $this->moneyToCents($amount);

        if ($totalCents < $count) {
            throw ValidationException::withMessages([
                'installment_count' => 'A quantidade de parcelas não pode gerar parcelas com valor zero.',
            ]);
        }

        $base = intdiv($totalCents, $count);
        $remainder = $totalCents % $count;
        $amounts = [];

        for ($index = 0; $index < $count; $index++) {
            $amounts[] = $this->centsToMoney(
                $base + ($index < $remainder ? 1 : 0),
            );
        }

        return $amounts;
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

    private function normalizeMoney(string $amount): string
    {
        return $this->centsToMoney($this->moneyToCents($amount));
    }
}
