<?php

namespace App\Services\Finance;

use App\Enums\CategoryType;
use App\Enums\FinancialTransactionOrigin;
use App\Models\CardStatementEntry;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use App\Services\AI\ExpenseClassificationAiService;

final class CardStatementAiEnrichmentService
{
    public function __construct(
        private readonly ExpenseClassificationAiService $aiService,
    ) {}

    public function enrich(
        Workspace $workspace,
        FinancialImport $financialImport,
    ): void {
        if (! (bool) config('financial_ai.enabled', true)) {
            return;
        }

        $entries = $financialImport->cardStatementEntries()
            ->where('is_reconciled', true)
            ->with('transactionInstallment.transaction')
            ->orderBy('id')
            ->get()
            ->filter(function (CardStatementEntry $entry): bool {
                $transaction = $entry->transactionInstallment?->transaction;

                if (
                    ! $transaction instanceof FinancialTransaction
                    || $transaction->origin !== FinancialTransactionOrigin::CardImport
                ) {
                    return false;
                }

                $payee = $transaction->getAttribute('payee_name');
                $categoryId = $transaction->getAttribute('category_id');

                return $categoryId === null
                    || ! is_string($payee)
                    || trim($payee) === ''
                    || trim($payee) === trim($entry->description);
            })
            ->values();

        if ($entries->isEmpty()) {
            return;
        }

        $validCategoryIds = $workspace->categories()
            ->where('type', CategoryType::Expense->value)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $validCategoryLookup = array_fill_keys($validCategoryIds, true);
        $classified = 0;
        $merchantUpdates = 0;
        $categoryUpdates = 0;
        $providers = [];
        $models = [];
        $batchSize = max(1, (int) config('financial_ai.batch_size', 40));

        foreach ($entries->chunk($batchSize) as $batch) {
            $result = $this->aiService->classify(
                $workspace,
                $batch->values(),
            );

            if ($result === null) {
                continue;
            }

            $providers[] = $result['provider'];
            $models[] = $result['model'];
            $byId = $batch->keyBy('id');

            foreach ($result['items'] as $suggestion) {
                $entry = $byId->get($suggestion['entry_id']);

                if (! $entry instanceof CardStatementEntry) {
                    continue;
                }

                $transaction = $entry->transactionInstallment?->transaction;

                if (
                    ! $transaction instanceof FinancialTransaction
                    || $transaction->origin !== FinancialTransactionOrigin::CardImport
                    || $transaction->workspace_id !== $workspace->id
                ) {
                    continue;
                }

                $updates = [];
                $merchantName = $suggestion['merchant_name'];

                if (
                    is_string($merchantName)
                    && $merchantName !== ''
                    && $suggestion['merchant_confidence'] >= (float) config(
                        'financial_ai.merchant_min_confidence',
                        0.70,
                    )
                ) {
                    $currentPayee = $transaction->getAttribute('payee_name');

                    if (
                        ! is_string($currentPayee)
                        || trim($currentPayee) === ''
                        || trim($currentPayee) === trim($entry->description)
                    ) {
                        $updates['payee_name'] = $merchantName;
                        $merchantUpdates++;
                    }
                }

                $categoryId = $suggestion['category_id'];

                if (
                    $transaction->getAttribute('category_id') === null
                    && is_int($categoryId)
                    && isset($validCategoryLookup[$categoryId])
                    && $suggestion['category_confidence'] >= (float) config(
                        'financial_ai.category_min_confidence',
                        0.80,
                    )
                ) {
                    $updates['category_id'] = $categoryId;
                    $categoryUpdates++;
                }

                if ($updates !== []) {
                    $transaction->update($updates);
                    $classified++;
                }
            }
        }

        if ($providers === []) {
            return;
        }

        $metadata = $financialImport->metadata ?? [];
        $metadata['ai_classification'] = [
            'providers' => array_values(array_unique($providers)),
            'models' => array_values(array_unique($models)),
            'classified_records' => $classified,
            'merchant_updates' => $merchantUpdates,
            'category_updates' => $categoryUpdates,
            'processed_at' => now()->toIso8601String(),
        ];

        $financialImport->update(['metadata' => $metadata]);
    }
}
