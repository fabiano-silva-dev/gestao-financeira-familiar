<?php

namespace App\Services\Reconciliation;

use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use Illuminate\Support\Collection;

final class BankReconciliationSuggestionService
{
    /**
     * @param  Collection<int, AccountMovement>  $movements
     * @return array<int, array{
     *     movement_id: int,
     *     occurred_on: string,
     *     description: string,
     *     amount: string,
     *     type: string,
     *     type_label: string,
     *     score: int,
     *     confidence: string,
     *     confidence_label: string,
     *     date_distance: int,
     *     is_suggestion: bool
     * }>
     */
    public function candidates(
        BankStatementEntry $entry,
        Collection $movements,
    ): array {
        $entryCents = $this->moneyToCents($entry->amount);

        return $movements
            ->filter(fn (AccountMovement $movement): bool => $movement->financial_account_id === $entry->financial_account_id
                && $this->moneyToCents($movement->amount) === $entryCents
                && ! $movement->is_reconciled)
            ->map(function (AccountMovement $movement) use ($entry): array {
                $dateDistance = (int) abs(
                    $entry->occurred_on->diffInDays($movement->occurred_on, false),
                );
                $descriptionScore = (int) round(
                    $this->descriptionSimilarity(
                        $entry->description.' '.($entry->memo ?? ''),
                        $movement->description,
                    ) * 15,
                );
                $dateScore = match (true) {
                    $dateDistance === 0 => 25,
                    $dateDistance === 1 => 20,
                    $dateDistance <= 3 => 14,
                    $dateDistance <= 7 => 8,
                    default => 0,
                };
                $score = min(100, 60 + $dateScore + $descriptionScore);
                [$confidence, $confidenceLabel] = match (true) {
                    $score >= 85 => ['high', 'Alta confiança'],
                    $score >= 72 => ['medium', 'Média confiança'],
                    default => ['low', 'Conferência manual'],
                };

                return [
                    'movement_id' => $movement->id,
                    'occurred_on' => $movement->occurred_on->toDateString(),
                    'description' => $movement->description,
                    'amount' => $movement->amount,
                    'type' => $movement->type->value,
                    'type_label' => $movement->type->label(),
                    'score' => $score,
                    'confidence' => $confidence,
                    'confidence_label' => $confidenceLabel,
                    'date_distance' => $dateDistance,
                    'is_suggestion' => $dateDistance <= 7,
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['date_distance'] <= 180)
            ->sort(function (array $left, array $right): int {
                return [$right['score'], $left['date_distance'], $right['movement_id']]
                    <=> [$left['score'], $right['date_distance'], $left['movement_id']];
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
