<?php

namespace App\Services\Reconciliation;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Models\BankStatementEntry;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use App\Services\Finance\ExpenseRefundService;

final class ExpenseRefundSuggestionService
{
    public function __construct(
        private readonly ImportedMovementInterpreter $interpreter,
        private readonly ExpenseRefundService $refundService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function candidates(
        Workspace $workspace,
        BankStatementEntry $entry,
    ): array {
        $entryCents = $this->interpreter->moneyToCents($entry->amount);

        if ($entryCents <= 0) {
            return [];
        }

        $from = $entry->occurred_on->copy()->subYear()->toDateString();
        $to = $entry->occurred_on->copy()->addDays(7)->toDateString();
        $looksLikeRefund = $this->interpreter->isLikelyRefund(
            $entry->description.' '.($entry->memo ?? ''),
        );

        return $workspace->financialTransactions()
            ->where('type', FinancialTransactionType::Expense->value)
            ->where('status', '!=', FinancialTransactionStatus::Cancelled->value)
            ->whereBetween('transaction_date', [$from, $to])
            ->with([
                'category:id,name,parent_id',
                'category.parent:id,name',
                'creditCard:id,name,last_four',
                'refunds:id,financial_transaction_id,amount,status',
            ])
            ->orderByDesc('transaction_date')
            ->limit(500)
            ->get()
            ->filter(
                fn (FinancialTransaction $transaction): bool => $entryCents <= $this->refundService->refundableCents($transaction),
            )
            ->map(function (FinancialTransaction $transaction) use (
                $entry,
                $entryCents,
                $looksLikeRefund,
            ): array {
                $remaining = $this->refundService->refundableCents($transaction);
                $original = $this->interpreter->moneyToCents(
                    (string) $transaction->amount,
                );
                $dateDistance = (int) abs(
                    $entry->occurred_on->diffInDays(
                        $transaction->transaction_date,
                        false,
                    ),
                );
                $similarity = $this->interpreter->descriptionSimilarity(
                    $entry->description.' '.($entry->memo ?? ''),
                    collect([
                        $transaction->description,
                        $transaction->payee_name,
                    ])->filter()->join(' '),
                );
                $dateScore = match (true) {
                    $dateDistance <= 7 => 20,
                    $dateDistance <= 30 => 15,
                    $dateDistance <= 90 => 10,
                    $dateDistance <= 180 => 5,
                    default => 0,
                };
                $exact = $entryCents === $remaining || $entryCents === $original;
                $score = min(
                    100,
                    25
                    + ($exact ? 35 : 15)
                    + $dateScore
                    + (int) round($similarity * 20)
                    + ($looksLikeRefund ? 10 : 0),
                );
                $sameParty = $looksLikeRefund || $this->sameParty(
                    $entry->description.' '.($entry->memo ?? ''),
                    collect([
                        $transaction->description,
                        $transaction->payee_name,
                    ])->filter()->join(' '),
                    $similarity,
                );
                [$confidence, $confidenceLabel] = match (true) {
                    $score >= 90 && $sameParty => ['high', 'Alta confiança'],
                    $score >= 75 && $sameParty => ['medium', 'Média confiança'],
                    default => ['low', 'Conferência manual'],
                };
                $category = $transaction->category;
                $parent = $category?->parent;
                $categoryName = $parent?->name ?? $category?->name;
                $subcategoryName = $parent !== null ? $category?->name : null;

                return [
                    'movement_id' => null,
                    'transaction_id' => $transaction->id,
                    'is_refund' => true,
                    'occurred_on' => $transaction->transaction_date->toDateString(),
                    'description' => 'Reembolso de '.$transaction->description,
                    'amount' => $entry->amount,
                    'type' => 'refund',
                    'type_label' => 'Reembolso',
                    'score' => $score,
                    'confidence' => $confidence,
                    'confidence_label' => $confidenceLabel,
                    'date_distance' => $dateDistance,
                    'is_suggestion' => $score >= 85 && $sameParty,
                    'remaining_refundable_amount' => $this->centsToMoney($remaining),
                    'related_transaction_id' => $transaction->id,
                    'related_description' => $transaction->description,
                    'related_type' => FinancialTransactionType::Expense->value,
                    'related_type_label' => 'Despesa original',
                    'related_account_name' => $transaction->creditCard?->name,
                    'related_competence_date' => $transaction->competence_date?->toDateString()
                        ?? $transaction->transaction_date->toDateString(),
                    'related_payee_name' => $transaction->payee_name,
                    'related_category_id' => $category?->id,
                    'related_category_name' => $categoryName,
                    'related_parent_category_id' => $parent?->id ?? $category?->id,
                    'related_parent_category_name' => $categoryName,
                    'related_subcategory_id' => $parent !== null ? $category?->id : null,
                    'related_subcategory_name' => $subcategoryName,
                    'related_is_transfer' => false,
                ];
            })
            ->sort(function (array $left, array $right): int {
                return [$right['score'], $left['date_distance'], $right['transaction_id']]
                    <=> [$left['score'], $right['date_distance'], $left['transaction_id']];
            })
            ->take(20)
            ->values()
            ->all();
    }

    private function sameParty(string $credit, string $expense, float $similarity): bool
    {
        if ($similarity >= 0.72) {
            return true;
        }

        $ignored = [
            'pix', 'ted', 'doc', 'boleto', 'enviado', 'enviada', 'recebido',
            'recebida', 'recebimento', 'pagamento', 'pago', 'transferencia',
            'debito', 'credito', 'compra', 'ltda', 'mei', 'epp', 'banco', 'conta',
        ];
        $tokens = function (string $value) use ($ignored): array {
            $normalized = $this->interpreter->normalize($value);

            return array_values(array_unique(array_filter(
                explode(' ', $normalized),
                static fn (string $token): bool => mb_strlen($token) >= 4
                    && ! in_array($token, $ignored, true),
            )));
        };

        return array_intersect($tokens($credit), $tokens($expense)) !== [];
    }

    private function centsToMoney(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
