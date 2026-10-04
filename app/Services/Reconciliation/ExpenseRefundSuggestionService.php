<?php

namespace App\Services\Reconciliation;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\ExpenseRefundDestination;
use App\Enums\ExpenseRefundStatus;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\ExpenseRefund;
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cardCandidates(
        Workspace $workspace,
        CardStatementEntry $entry,
    ): array {
        $entryCents = abs($this->interpreter->moneyToCents($entry->amount));

        if ($entryCents <= 0 || $entry->is_payment) {
            return [];
        }

        $from = $entry->purchased_on->copy()->subYear()->toDateString();
        $to = $entry->purchased_on->copy()->addDays(7)->toDateString();

        $candidates = $workspace->financialTransactions()
            ->where('type', FinancialTransactionType::Expense->value)
            ->where('status', '!=', FinancialTransactionStatus::Cancelled->value)
            ->where('credit_card_id', $entry->credit_card_id)
            ->where(function ($query) use ($entry, $from, $to): void {
                $query->whereBetween('transaction_date', [$from, $to])
                    ->orWhereHas(
                        'installments',
                        fn ($installments) => $installments->where(
                            'credit_card_invoice_id',
                            $entry->credit_card_invoice_id,
                        ),
                    );
            })
            ->with([
                'category:id,name,parent_id',
                'category.parent:id,name',
                'creditCard:id,name,last_four',
                'refunds:id,financial_transaction_id,amount,status,destination_type,credit_card_invoice_id',
                'installments:id,financial_transaction_id,credit_card_invoice_id',
            ])
            ->orderByDesc('transaction_date')
            ->limit(500)
            ->get()
            ->filter(function (FinancialTransaction $transaction) use ($entry, $entryCents): bool {
                if ($entryCents <= $this->refundService->refundableCents($transaction)) {
                    return true;
                }

                return $this->matchingUnlinkedRefund($transaction, $entry, $entryCents) !== null;
            })
            ->map(function (FinancialTransaction $transaction) use ($entry, $entryCents): array {
                $remaining = $this->refundService->refundableCents($transaction);
                $original = $this->interpreter->moneyToCents((string) $transaction->amount);
                $dateDistance = (int) abs(
                    $entry->purchased_on->diffInDays($transaction->transaction_date, false),
                );
                $similarity = $this->interpreter->descriptionSimilarity(
                    $entry->description,
                    collect([
                        $transaction->description,
                        $transaction->payee_name,
                    ])->filter()->join(' '),
                );
                $dateScore = match (true) {
                    $dateDistance <= 7 => 20,
                    $dateDistance <= 30 => 15,
                    $dateDistance <= 90 => 10,
                    default => 0,
                };
                $exact = $entryCents === $remaining || $entryCents === $original;
                $sameInvoice = $transaction->installments->contains(
                    'credit_card_invoice_id',
                    $entry->credit_card_invoice_id,
                );
                $score = min(
                    100,
                    25
                    + ($exact ? 35 : 10)
                    + ($sameInvoice ? 15 : 0)
                    + $dateScore
                    + (int) round($similarity * 15),
                );
                $sameParty = $this->sameParty(
                    $entry->description,
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

                return [
                    'movement_id' => null,
                    'transaction_id' => $transaction->id,
                    'installment_id' => null,
                    'is_refund' => true,
                    'exact_amount' => $exact,
                    'same_invoice' => $sameInvoice,
                    'occurred_on' => $transaction->transaction_date->toDateString(),
                    'description' => 'Reembolso de '.$transaction->description,
                    'amount' => $this->centsToMoney($entryCents),
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
                    'related_subcategory_name' => $parent !== null ? $category?->name : null,
                    'related_is_transfer' => false,
                ];
            })
            ->sort(function (array $left, array $right): int {
                return [$right['score'], $left['date_distance'], $right['transaction_id']]
                    <=> [$left['score'], $right['date_distance'], $left['transaction_id']];
            })
            ->take(20)
            ->values();

        $soleExact = $candidates->filter(
            fn (array $candidate): bool => $candidate['exact_amount'] === true
                && $candidate['same_invoice'] === true,
        );

        if ($soleExact->count() === 1) {
            $transactionId = $soleExact->first()['transaction_id'];
            $candidates = $candidates->map(function (array $candidate) use ($transactionId): array {
                if ($candidate['transaction_id'] !== $transactionId || $candidate['is_suggestion'] === true) {
                    return $candidate;
                }

                $candidate['is_suggestion'] = true;
                $candidate['confidence'] = 'medium';
                $candidate['confidence_label'] = 'Média confiança';

                return $candidate;
            });
        }

        return $candidates
            ->map(function (array $candidate): array {
                unset($candidate['exact_amount'], $candidate['same_invoice']);

                return $candidate;
            })
            ->all();
    }

    public function matchingUnlinkedRefund(
        FinancialTransaction $transaction,
        CardStatementEntry $entry,
        int $entryCents,
    ): ?ExpenseRefund {
        $refund = $transaction->refunds()
            ->where('status', ExpenseRefundStatus::Confirmed->value)
            ->where('destination_type', ExpenseRefundDestination::CreditCard->value)
            ->where('credit_card_invoice_id', $entry->credit_card_invoice_id)
            ->where('amount', $this->centsToMoney($entryCents))
            ->whereDoesntHave('cardStatementEntry')
            ->first();

        return $refund instanceof ExpenseRefund ? $refund : null;
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
