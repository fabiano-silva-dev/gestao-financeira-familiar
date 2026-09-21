<?php

namespace App\Services\Reconciliation;

use App\Models\CardStatementEntry;
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
                    'installment_id' => $installment->id,
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

    private function moneyToCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }
}
