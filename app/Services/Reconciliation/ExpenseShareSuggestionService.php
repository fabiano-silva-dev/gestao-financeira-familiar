<?php

namespace App\Services\Reconciliation;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Models\BankStatementEntry;
use App\Models\ExpenseShare;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use App\Services\Finance\ExpenseRefundService;
use App\Services\Finance\ExpenseShareService;

final class ExpenseShareSuggestionService
{
    public function __construct(
        private readonly ImportedMovementInterpreter $interpreter,
        private readonly ExpenseRefundService $refundService,
        private readonly ExpenseShareService $shareService,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function candidates(
        Workspace $workspace,
        BankStatementEntry $entry,
    ): array {
        $entryCents = $this->interpreter->moneyToCents($entry->amount);

        if ($entryCents <= 0) {
            return [];
        }

        $from = $entry->occurred_on->copy()->subYear()->toDateString();
        $to = $entry->occurred_on->copy()->addDays(30)->toDateString();

        return $workspace->financialTransactions()
            ->where('type', FinancialTransactionType::Expense->value)
            ->where('status', '!=', FinancialTransactionStatus::Cancelled->value)
            ->whereBetween('transaction_date', [$from, $to])
            ->with([
                'account:id,name',
                'category:id,name,parent_id',
                'category.parent:id,name',
                'creditCard:id,name,last_four',
                'refunds:id,financial_transaction_id,amount,status',
                'expenseShare.receipts:id,expense_share_id,amount',
            ])
            ->orderByDesc('transaction_date')
            ->limit(500)
            ->get()
            ->filter(function (FinancialTransaction $transaction) use ($entryCents): bool {
                $share = $transaction->expenseShare;

                if ($share instanceof ExpenseShare) {
                    return $entryCents
                        <= $this->shareService->remainingExpectedCents($share);
                }

                $available = max(
                    0,
                    $this->interpreter->moneyToCents(
                        (string) $transaction->amount,
                    ) - $this->refundService->refundedCents($transaction),
                );

                return $entryCents <= $available;
            })
            ->map(function (FinancialTransaction $transaction) use (
                $entry,
                $entryCents,
            ): array {
                $share = $transaction->expenseShare;
                $expected = $share instanceof ExpenseShare
                    ? $this->interpreter->moneyToCents(
                        (string) $share->expected_amount,
                    )
                    : 0;
                $received = $share instanceof ExpenseShare
                    ? $this->shareService->receivedCents($share)
                    : 0;
                $remaining = $share instanceof ExpenseShare
                    ? max(0, $expected - $received)
                    : max(
                        0,
                        $this->interpreter->moneyToCents(
                            (string) $transaction->amount,
                        ) - $this->refundService->refundedCents($transaction),
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
                $existingShare = $share instanceof ExpenseShare;
                $score = min(
                    100,
                    ($existingShare ? 55 : 20)
                    + ($entryCents === $remaining ? 25 : 10)
                    + ($dateDistance <= 30 ? 10 : ($dateDistance <= 90 ? 5 : 0))
                    + (int) round($similarity * 10),
                );
                [$confidence, $confidenceLabel] = match (true) {
                    $score >= 85 => ['high', 'Alta confiança'],
                    $score >= 70 => ['medium', 'Média confiança'],
                    default => ['low', 'Conferência manual'],
                };
                $category = $transaction->category;
                $parent = $category?->parent;
                $categoryName = $parent?->name ?? $category?->name;

                return [
                    'movement_id' => null,
                    'transaction_id' => $transaction->id,
                    'is_expense_share' => true,
                    'occurred_on' => $transaction->transaction_date->toDateString(),
                    'description' => $transaction->description,
                    'amount' => $entry->amount,
                    'type' => 'expense_share',
                    'type_label' => 'Rateio',
                    'score' => $score,
                    'confidence' => $confidence,
                    'confidence_label' => $confidenceLabel,
                    'date_distance' => $dateDistance,
                    'is_suggestion' => $existingShare && $score >= 70,
                    'expected_shared_amount' => $existingShare
                        ? $this->centsToMoney($expected)
                        : null,
                    'received_shared_amount' => $this->centsToMoney($received),
                    'remaining_shared_amount' => $existingShare
                        ? $this->centsToMoney($remaining)
                        : null,
                    'related_transaction_id' => $transaction->id,
                    'related_description' => $transaction->description,
                    'related_type' => FinancialTransactionType::Expense->value,
                    'related_type_label' => 'Despesa original',
                    'related_account_name' => $transaction->creditCard?->name
                        ?? $transaction->account?->name,
                    'related_competence_date' => $transaction->competence_date?->toDateString()
                        ?? $transaction->transaction_date->toDateString(),
                    'related_payee_name' => $transaction->payee_name,
                    'related_category_id' => $category?->id,
                    'related_category_name' => $categoryName,
                    'related_parent_category_id' => $parent?->id ?? $category?->id,
                    'related_parent_category_name' => $categoryName,
                    'related_subcategory_id' => $parent !== null
                        ? $category?->id
                        : null,
                    'related_subcategory_name' => $parent !== null
                        ? $category?->name
                        : null,
                    'related_is_transfer' => false,
                    'card_name' => $transaction->creditCard?->name,
                ];
            })
            ->sort(function (array $left, array $right): int {
                return [
                    $right['score'],
                    $left['date_distance'],
                    $right['transaction_id'],
                ] <=> [
                    $left['score'],
                    $right['date_distance'],
                    $left['transaction_id'],
                ];
            })
            ->take(30)
            ->values()
            ->all();
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
