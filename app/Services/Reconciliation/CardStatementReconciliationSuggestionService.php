<?php

namespace App\Services\Reconciliation;

use App\Enums\FinancialTransactionStatus;
use App\Models\CardStatementEntry;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use Illuminate\Support\Collection;

final class CardStatementReconciliationSuggestionService
{
    /**
     * @param  Collection<int, TransactionInstallment>  $installments
     * @return array<int, array{
     *     installment_id: int,
     *     transaction_id: int,
     *     transaction_date: string,
     *     description: string,
     *     amount: string,
     *     installment_number: int,
     *     total_installments: int,
     *     score: int,
     *     confidence: string,
     *     confidence_label: string,
     *     date_distance: int,
     *     is_suggestion: bool
     * }>
     */
    public function candidates(
        CardStatementEntry $entry,
        Collection $installments,
    ): array {
        $entryCents = $this->moneyToCents($entry->amount);

        return $installments
            ->filter(fn (TransactionInstallment $installment): bool => $installment->credit_card_invoice_id === $entry->credit_card_invoice_id
                && $this->moneyToCents($installment->amount) === $entryCents
                && $installment->cardStatementEntry === null
                && (
                    $entry->installment_number === null
                    || $entry->installment_number === $installment->installment_number
                )
                && (
                    $entry->total_installments === null
                    || $entry->total_installments === $installment->total_installments
                ))
            ->map(function (TransactionInstallment $installment) use ($entry): array {
                $transaction = $installment->transaction;
                $dateDistance = (int) abs(
                    $entry->purchased_on->diffInDays($transaction->transaction_date, false),
                );
                $descriptionScore = (int) round(
                    $this->descriptionSimilarity(
                        $entry->description,
                        $transaction->description,
                    ) * 15,
                );
                $dateScore = match (true) {
                    $dateDistance === 0 => 10,
                    $dateDistance === 1 => 8,
                    $dateDistance <= 3 => 5,
                    $dateDistance <= 7 => 3,
                    default => 0,
                };
                $installmentScore = $entry->installment_number !== null ? 20 : 0;
                $score = min(100, 55 + $installmentScore + $dateScore + $descriptionScore);
                [$confidence, $confidenceLabel] = match (true) {
                    $score >= 85 => ['high', 'Alta confiança'],
                    $score >= 75 => ['medium', 'Média confiança'],
                    default => ['low', 'Conferência manual'],
                };

                return [
                    'kind' => 'installment',
                    'installment_id' => $installment->id,
                    'recurrence_transaction_id' => null,
                    'transaction_id' => $transaction->id,
                    'transaction_date' => $transaction->transaction_date->toDateString(),
                    'description' => $transaction->description,
                    'amount' => $installment->amount,
                    'installment_number' => $installment->installment_number,
                    'total_installments' => $installment->total_installments,
                    'score' => $score,
                    'confidence' => $confidence,
                    'confidence_label' => $confidenceLabel,
                    'date_distance' => $dateDistance,
                    'is_suggestion' => $score >= 75,
                    'is_recurrence_forecast' => false,
                    'amount_difference' => '0.00',
                ];
            })
            ->sort(function (array $left, array $right): int {
                return [$right['score'], $left['date_distance'], $right['installment_id']]
                    <=> [$left['score'], $right['date_distance'], $left['installment_id']];
            })
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, FinancialTransaction>  $transactions
     * @return array<int, array<string, mixed>>
     */
    public function recurrenceCandidates(
        CardStatementEntry $entry,
        Collection $transactions,
    ): array {
        $entryCents = $this->moneyToCents($entry->amount);

        return $transactions
            ->filter(function (FinancialTransaction $transaction) use ($entry, $entryCents): bool {
                if (
                    $transaction->status !== FinancialTransactionStatus::Planned
                    || $transaction->financial_recurrence_id === null
                    || $transaction->credit_card_id !== $entry->credit_card_id
                    || $transaction->installments()->exists()
                ) {
                    return false;
                }

                $expectedCents = $this->moneyToCents($transaction->amount);
                $tolerance = min(5000, max(500, (int) round($expectedCents * 0.10)));

                return abs($entryCents - $expectedCents) <= $tolerance;
            })
            ->map(function (FinancialTransaction $transaction) use ($entry, $entryCents): array {
                $expectedCents = $this->moneyToCents($transaction->amount);
                $difference = abs($entryCents - $expectedCents);
                $dateDistance = (int) abs(
                    $entry->purchased_on->diffInDays($transaction->transaction_date, false),
                );
                $descriptionScore = (int) round(
                    $this->descriptionSimilarity($entry->description, $transaction->description) * 30,
                );
                $amountScore = $difference === 0
                    ? 45
                    : max(15, 45 - (int) round(($difference / max(1, $expectedCents)) * 300));
                $dateScore = match (true) {
                    $dateDistance === 0 => 20,
                    $dateDistance <= 3 => 15,
                    $dateDistance <= 7 => 10,
                    $dateDistance <= 15 => 5,
                    default => 0,
                };
                $score = min(100, $amountScore + $dateScore + $descriptionScore);
                [$confidence, $confidenceLabel] = match (true) {
                    $score >= 85 => ['high', 'Alta confiança'],
                    $score >= 70 => ['medium', 'Média confiança'],
                    default => ['low', 'Conferência manual'],
                };

                return [
                    'kind' => 'recurrence',
                    'installment_id' => null,
                    'recurrence_transaction_id' => $transaction->id,
                    'transaction_id' => $transaction->id,
                    'transaction_date' => $transaction->transaction_date->toDateString(),
                    'description' => $transaction->description,
                    'amount' => $transaction->amount,
                    'installment_number' => 1,
                    'total_installments' => 1,
                    'score' => $score,
                    'confidence' => $confidence,
                    'confidence_label' => $confidenceLabel,
                    'date_distance' => $dateDistance,
                    'is_suggestion' => $score >= 70,
                    'is_recurrence_forecast' => true,
                    'amount_difference' => $this->centsToMoney($entryCents - $expectedCents),
                ];
            })
            ->sortByDesc('score')
            ->take(20)
            ->values()
            ->all();
    }

    private function descriptionSimilarity(string $left, string $right): float
    {
        $left = $this->normalize($left);
        $right = $this->normalize($right);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right || str_contains($left, $right) || str_contains($right, $left)) {
            return 1.0;
        }

        similar_text($left, $right, $characterPercentage);
        $leftTokens = array_values(array_unique(array_filter(
            explode(' ', $left),
            static fn (string $token): bool => mb_strlen($token) >= 3,
        )));
        $rightTokens = array_values(array_unique(array_filter(
            explode(' ', $right),
            static fn (string $token): bool => mb_strlen($token) >= 3,
        )));
        $union = array_unique([...$leftTokens, ...$rightTokens]);
        $tokenSimilarity = $union === []
            ? 0.0
            : count(array_intersect($leftTokens, $rightTokens)) / count($union);

        return max($characterPercentage / 100, $tokenSimilarity);
    }

    private function normalize(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($transliterated) ? $transliterated : $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function centsToMoney(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $formatted = sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);

        return $negative ? '-'.$formatted : $formatted;
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
