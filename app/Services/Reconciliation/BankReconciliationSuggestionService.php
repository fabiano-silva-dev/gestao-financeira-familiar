<?php

namespace App\Services\Reconciliation;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\FinancialTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class BankReconciliationSuggestionService
{
    /** Transferência e lançamento avulso só combinam perto da data do extrato. */
    private const NEAR_MATCH_DAYS = 7;

    /** Pré-agendamento pode ser pago alguns dias antes ou depois, até esta janela. */
    private const PLANNED_MATCH_DAYS = 180;

    /** @var Collection<int, FinancialTransaction>|null */
    private ?Collection $plannedTransactions = null;

    /**
     * @param  Collection<int, AccountMovement>  $movements
     * @return array<int, array{
     *     movement_id: int|null,
     *     planned_transaction_id: int|null,
     *     is_planned: bool,
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

        $movementCandidates = $movements
            ->filter(fn (AccountMovement $movement): bool => $movement->financial_account_id === $entry->financial_account_id
                && $this->moneyToCents($movement->amount) === $entryCents
                && ! $movement->is_reconciled)
            ->map(function (AccountMovement $movement) use ($entry): array {
                $dateDistance = $this->dateDistance($entry->occurred_on, $movement->occurred_on);
                $scored = $this->score($entry, $movement->description, $dateDistance, false);

                return [
                    'movement_id' => $movement->id,
                    'planned_transaction_id' => null,
                    'is_planned' => false,
                    'occurred_on' => $movement->occurred_on->toDateString(),
                    'description' => $movement->description,
                    'amount' => $movement->amount,
                    'type' => $movement->type->value,
                    'type_label' => $movement->type->label(),
                    ...$scored,
                    'date_distance' => $dateDistance,
                    'is_suggestion' => $dateDistance <= self::NEAR_MATCH_DAYS,
                ];
            })
            ->filter(function (array $candidate) use ($movements): bool {
                if ($candidate['date_distance'] <= self::NEAR_MATCH_DAYS) {
                    return true;
                }

                if ($candidate['date_distance'] > self::PLANNED_MATCH_DAYS) {
                    return false;
                }

                $movement = $movements->firstWhere('id', $candidate['movement_id']);

                return $movement?->transaction?->status === FinancialTransactionStatus::Planned;
            });

        return $movementCandidates
            ->concat($this->plannedCandidates($entry, $entryCents))
            ->sort(function (array $left, array $right): int {
                return [$right['score'], $left['date_distance'], $right['movement_id'] ?? 0]
                    <=> [$left['score'], $right['date_distance'], $left['movement_id'] ?? 0];
            })
            ->take(20)
            ->values()
            ->all();
    }

    public function plannedTransaction(?int $id): ?FinancialTransaction
    {
        if ($id === null || ! $this->plannedTransactions instanceof Collection) {
            return null;
        }

        $transaction = $this->plannedTransactions->firstWhere('id', $id);

        return $transaction instanceof FinancialTransaction ? $transaction : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plannedCandidates(BankStatementEntry $entry, int $entryCents): array
    {
        return $this->plannedTransactions($entry->workspace_id)
            ->filter(function (FinancialTransaction $transaction) use ($entry, $entryCents): bool {
                return $transaction->financial_account_id === $entry->financial_account_id
                    && $this->moneyToCents($this->signedAmount($transaction)) === $entryCents;
            })
            ->map(function (FinancialTransaction $transaction) use ($entry): array {
                $scheduled = $transaction->due_date ?? $transaction->transaction_date;
                $dateDistance = $this->dateDistance($entry->occurred_on, $scheduled);
                $near = $dateDistance <= self::NEAR_MATCH_DAYS;
                $scored = $this->score($entry, $transaction->description, $dateDistance, $near);

                return [
                    'movement_id' => null,
                    'planned_transaction_id' => $transaction->id,
                    'is_planned' => true,
                    'occurred_on' => $scheduled->toDateString(),
                    'description' => $transaction->description,
                    'amount' => $this->signedAmount($transaction),
                    'type' => $transaction->type->value,
                    'type_label' => $transaction->type->label(),
                    ...$scored,
                    'date_distance' => $dateDistance,
                    'is_suggestion' => $near,
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['date_distance'] <= self::PLANNED_MATCH_DAYS)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, FinancialTransaction>
     */
    private function plannedTransactions(int $workspaceId): Collection
    {
        if ($this->plannedTransactions instanceof Collection) {
            return $this->plannedTransactions;
        }

        return $this->plannedTransactions = FinancialTransaction::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', FinancialTransactionStatus::Planned->value)
            ->whereNull('settled_on')
            ->whereNull('credit_card_id')
            ->whereNotNull('financial_account_id')
            ->whereIn('type', [
                FinancialTransactionType::Expense->value,
                FinancialTransactionType::Income->value,
            ])
            ->whereDoesntHave('accountMovements')
            ->with([
                'account:id,name',
                'category:id,name,parent_id',
                'category.parent:id,name',
            ])
            ->get();
    }

    /**
     * @return array{score: int, confidence: string, confidence_label: string}
     */
    private function score(
        BankStatementEntry $entry,
        string $description,
        int $dateDistance,
        bool $preferNearPlanned,
    ): array {
        $descriptionScore = (int) round(
            $this->descriptionSimilarity(
                $entry->description.' '.($entry->memo ?? ''),
                $description,
            ) * 15,
        );
        $dateScore = match (true) {
            $preferNearPlanned => 25,
            $dateDistance === 0 => 25,
            $dateDistance === 1 => 20,
            $dateDistance <= 3 => 14,
            $dateDistance <= self::NEAR_MATCH_DAYS => 8,
            default => 0,
        };
        $score = min(100, 60 + $dateScore + $descriptionScore);

        [$confidence, $confidenceLabel] = match (true) {
            $score >= 85 => ['high', 'Alta confiança'],
            $score >= 72 => ['medium', 'Média confiança'],
            default => ['low', 'Conferência manual'],
        };

        return [
            'score' => $score,
            'confidence' => $confidence,
            'confidence_label' => $confidenceLabel,
        ];
    }

    private function dateDistance(CarbonInterface $left, CarbonInterface $right): int
    {
        return (int) abs($left->diffInDays($right, false));
    }

    private function signedAmount(FinancialTransaction $transaction): string
    {
        $amount = ltrim((string) $transaction->amount, '-');

        return $transaction->type === FinancialTransactionType::Expense
            ? '-'.$amount
            : $amount;
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
