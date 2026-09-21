<?php

namespace App\Services\Finance;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Reconciliation\CardStatementReconciliationService;
use App\Services\Reconciliation\CardStatementReconciliationSuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CardStatementMaterializationService
{
    public function __construct(
        private readonly CardStatementReconciliationSuggestionService $suggestionService,
        private readonly CardStatementReconciliationService $reconciliationService,
    ) {}

    public function materialize(
        Workspace $workspace,
        CreditCard $card,
        CreditCardInvoice $invoice,
        CardStatementEntry $entry,
        User $user,
    ): void {
        if ($entry->is_reconciled || $this->moneyToCents($entry->amount) <= 0) {
            return;
        }

        $availableInstallments = $invoice->installments()
            ->with(['transaction', 'cardStatementEntry'])
            ->get()
            ->filter(
                fn (TransactionInstallment $installment): bool => $installment->cardStatementEntry === null,
            )
            ->values();
        $candidates = $this->suggestionService->candidates(
            $entry,
            $availableInstallments,
        );

        if ($this->canAutoReconcile($candidates)) {
            $installment = $availableInstallments->firstWhere(
                'id',
                $candidates[0]['installment_id'],
            );

            if ($installment instanceof TransactionInstallment) {
                $this->reconciliationService->reconcile(
                    $workspace,
                    $invoice,
                    $entry,
                    $installment,
                    $user,
                );

                return;
            }
        }

        if ($this->hasRelevantCandidate($candidates)) {
            return;
        }

        if ($invoice->status !== CreditCardInvoiceStatus::Open) {
            return;
        }

        $transaction = $this->createTransaction(
            $workspace,
            $card,
            $entry,
        );
        $currentInstallment = $this->createCurrentAndFutureInstallments(
            $transaction,
            $card,
            $invoice,
            $entry,
        );

        $this->reconciliationService->reconcile(
            $workspace,
            $invoice,
            $entry,
            $currentInstallment,
            $user,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function canAutoReconcile(array $candidates): bool
    {
        $first = $candidates[0] ?? null;

        if (! is_array($first) || (int) ($first['score'] ?? 0) < 80) {
            return false;
        }

        $second = $candidates[1] ?? null;

        return ! is_array($second)
            || ((int) $first['score'] - (int) ($second['score'] ?? 0)) >= 10;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function hasRelevantCandidate(array $candidates): bool
    {
        $first = $candidates[0] ?? null;

        return is_array($first) && (int) ($first['score'] ?? 0) >= 75;
    }

    private function createTransaction(
        Workspace $workspace,
        CreditCard $card,
        CardStatementEntry $entry,
    ): FinancialTransaction {
        $installmentNumber = max(1, $entry->installment_number ?? 1);
        $totalInstallments = max(
            $installmentNumber,
            $entry->total_installments ?? 1,
        );
        $installmentCents = $this->moneyToCents($entry->amount);
        $totalCents = $installmentCents * $totalInstallments;
        $categoryId = $this->knownCategoryId($workspace, $entry->description);

        return $workspace->financialTransactions()->create([
            'type' => FinancialTransactionType::Expense,
            'transaction_date' => $entry->purchased_on->toDateString(),
            'competence_date' => $entry->purchased_on->toDateString(),
            'description' => $entry->description,
            'amount' => $this->centsToMoney($totalCents),
            'financial_account_id' => null,
            'credit_card_id' => $card->id,
            'category_id' => $categoryId,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::CreditCard,
            'payee_name' => $entry->description,
            'payment_instructions' => null,
            'due_date' => null,
            'settled_on' => null,
            'status' => FinancialTransactionStatus::Confirmed,
            'origin' => FinancialTransactionOrigin::CardImport,
            'notes' => $totalInstallments > 1
                ? sprintf(
                    'Compra importada da fatura. Valor total estimado a partir de %d parcelas de %s.',
                    $totalInstallments,
                    $entry->amount,
                )
                : 'Compra criada automaticamente a partir da fatura importada.',
        ]);
    }

    private function createCurrentAndFutureInstallments(
        FinancialTransaction $transaction,
        CreditCard $card,
        CreditCardInvoice $currentInvoice,
        CardStatementEntry $entry,
    ): TransactionInstallment {
        $currentNumber = max(1, $entry->installment_number ?? 1);
        $totalInstallments = max(
            $currentNumber,
            $entry->total_installments ?? 1,
        );
        $currentInstallment = null;

        for ($number = $currentNumber; $number <= $totalInstallments; $number++) {
            $offset = $number - $currentNumber;
            $invoice = $offset === 0
                ? $currentInvoice
                : $this->resolveInvoice(
                    $card,
                    CarbonImmutable::parse($currentInvoice->reference_month)
                        ->addMonths($offset),
                );

            $installment = $transaction->installments()->create([
                'workspace_id' => $transaction->workspace_id,
                'credit_card_invoice_id' => $invoice->id,
                'installment_number' => $number,
                'total_installments' => $totalInstallments,
                'amount' => $entry->amount,
                'competence_month' => $invoice->reference_month->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'expected_payment_date' => $invoice->due_date->toDateString(),
                'paid_at' => null,
                'status' => TransactionInstallmentStatus::Open,
            ]);

            if ($number === $currentNumber) {
                $currentInstallment = $installment;
            }

            $this->recalculateInvoice($invoice);
        }

        if (! $currentInstallment instanceof TransactionInstallment) {
            throw ValidationException::withMessages([
                'file' => 'Não foi possível gerar a parcela da compra importada.',
            ]);
        }

        return $currentInstallment;
    }

    private function resolveInvoice(
        CreditCard $card,
        CarbonImmutable $referenceMonth,
    ): CreditCardInvoice {
        $reference = $referenceMonth->startOfMonth();
        $dueDate = $this->dateInMonth($reference, $card->due_day);
        $closingMonth = $card->due_day > $card->closing_day
            ? $reference
            : $reference->subMonth();
        $closingDate = $this->dateInMonth($closingMonth, $card->closing_day);
        $invoice = CreditCardInvoice::query()->firstOrCreate(
            [
                'workspace_id' => $card->workspace_id,
                'credit_card_id' => $card->id,
                'reference_month' => $reference->toDateString(),
            ],
            [
                'closing_date' => $closingDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'calculated_amount' => '0.00',
                'statement_amount' => null,
                'paid_amount' => '0.00',
                'status' => CreditCardInvoiceStatus::Open,
            ],
        );

        if ($invoice->status !== CreditCardInvoiceStatus::Open) {
            throw ValidationException::withMessages([
                'file' => 'Uma das faturas futuras da compra parcelada já está fechada ou paga.',
            ]);
        }

        return $invoice;
    }

    private function recalculateInvoice(CreditCardInvoice $invoice): void
    {
        $amount = (string) $invoice->installments()
            ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
            ->sum('amount');

        $invoice->update([
            'calculated_amount' => $this->centsToMoney(
                $this->moneyToCents($amount),
            ),
        ]);
    }

    private function knownCategoryId(
        Workspace $workspace,
        string $description,
    ): ?int {
        $normalized = $this->normalize($description);

        $match = $workspace->financialTransactions()
            ->where('type', FinancialTransactionType::Expense->value)
            ->whereNotNull('category_id')
            ->where('status', '!=', FinancialTransactionStatus::Cancelled->value)
            ->latest('id')
            ->limit(500)
            ->get(['id', 'category_id', 'description', 'payee_name'])
            ->first(function (FinancialTransaction $transaction) use ($normalized): bool {
                $payee = $transaction->getAttribute('payee_name');
                $payee = is_string($payee) && $payee !== ''
                    ? $payee
                    : (string) $transaction->getAttribute('description');

                return $this->normalize($payee) === $normalized
                    || $this->normalize((string) $transaction->getAttribute('description')) === $normalized;
            });

        if (! $match instanceof FinancialTransaction) {
            return null;
        }

        $categoryId = $match->getAttribute('category_id');

        return is_int($categoryId) ? $categoryId : (int) $categoryId;
    }

    private function dateInMonth(
        CarbonImmutable $month,
        int $day,
    ): CarbonImmutable {
        $base = $month->startOfMonth();

        return $base->addDays(min($day, $base->daysInMonth) - 1);
    }

    private function normalize(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($transliterated) ? $transliterated : $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function moneyToCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
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
