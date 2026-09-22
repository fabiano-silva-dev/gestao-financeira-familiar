<?php

namespace App\Services\Reconciliation;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialTransactionType;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\CreditCardInvoice;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\CardStatementMaterializationService;
use App\Services\Finance\ClassificationRuleMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportReconciliationReprocessor
{
    public function __construct(
        private readonly BankReconciliationService $bankReconciliation,
        private readonly BankReconciliationSuggestionService $bankSuggestions,
        private readonly CardStatementReconciliationService $cardReconciliation,
        private readonly CardStatementMaterializationService $cardMaterialization,
        private readonly ReconciliationEntryService $entryActions,
        private readonly ClassificationRuleMatcher $ruleMatcher,
    ) {}

    /**
     * @return array{reopened: int, processed: int}
     */
    public function reprocess(
        Workspace $workspace,
        FinancialImport $import,
        User $user,
    ): array {
        abort_unless($import->workspace_id === $workspace->id, 404);
        abort_unless($import->status === FinancialImportStatus::Completed, 404);

        return DB::transaction(function () use ($workspace, $import, $user): array {
            $bankEntries = $import->bankStatementEntries()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $cardEntries = $import->cardStatementEntries()
                ->with(['creditCard', 'invoice'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($bankEntries as $entry) {
                $this->reopenBank($workspace, $entry);
            }

            foreach ($cardEntries as $entry) {
                $this->reopenCard($workspace, $entry);
            }

            foreach ($bankEntries as $entry) {
                $this->processBank($workspace, $entry->refresh(), $user);
            }

            foreach ($cardEntries as $entry) {
                $this->processCard(
                    $workspace,
                    $entry->refresh()->load(['creditCard', 'invoice']),
                    $user,
                );
            }

            return [
                'reopened' => $bankEntries->count() + $cardEntries->count(),
                'processed' => $bankEntries->count() + $cardEntries->count(),
            ];
        });
    }

    private function reopenBank(Workspace $workspace, BankStatementEntry $entry): void
    {
        if ($entry->is_reconciled || $entry->account_movement_id !== null) {
            $this->bankReconciliation->undo($workspace, $entry);
            $entry->refresh();
        }

        $this->clearIgnored($entry);
    }

    private function reopenCard(Workspace $workspace, CardStatementEntry $entry): void
    {
        $invoice = $entry->invoice;

        if (
            $invoice instanceof CreditCardInvoice
            && ($entry->is_reconciled || $entry->transaction_installment_id !== null)
        ) {
            $this->cardReconciliation->undo($workspace, $invoice, $entry);
            $entry->refresh();
        }

        $this->clearIgnored($entry);
    }

    private function processBank(
        Workspace $workspace,
        BankStatementEntry $entry,
        User $user,
    ): void {
        if ($entry->is_reconciled || $entry->is_ignored) {
            return;
        }

        $this->applyRuleClassification($workspace, $entry, true);
        $entry->refresh();
        $movements = $this->unmatchedMovements($workspace, $entry->financial_account_id);
        $candidates = $this->bankSuggestions->candidates($entry, $movements);

        if ($this->canAutoReconcile($candidates)) {
            $movement = $movements->firstWhere('id', $candidates[0]['movement_id']);

            if ($movement instanceof AccountMovement) {
                $this->bankReconciliation->reconcile(
                    $workspace,
                    $entry,
                    $movement,
                    $user,
                );
                $this->entryActions->applyDraftToRelatedBank(
                    $entry->refresh()->load('accountMovement.transaction'),
                );

                return;
            }
        }

        $rule = $this->ruleMatcher->match($workspace, $entry->description);

        if (! is_array($rule)) {
            return;
        }

        try {
            $this->entryActions->createBankTransaction(
                $workspace,
                $entry->refresh(),
                $user,
            );
        } catch (ValidationException) {
            return;
        }
    }

    private function processCard(
        Workspace $workspace,
        CardStatementEntry $entry,
        User $user,
    ): void {
        if ($entry->is_reconciled || $entry->is_ignored) {
            return;
        }

        $this->applyRuleClassification($workspace, $entry, false);
        $entry->refresh()->load(['creditCard', 'invoice']);
        $invoice = $entry->invoice;

        if (! $invoice instanceof CreditCardInvoice) {
            return;
        }

        $this->cardMaterialization->materialize(
            $workspace,
            $entry->creditCard,
            $invoice,
            $entry,
            $user,
        );
        $this->entryActions->applyDraftToRelatedCard(
            $entry->refresh()->load('transactionInstallment.transaction'),
        );
    }

    private function applyRuleClassification(
        Workspace $workspace,
        BankStatementEntry|CardStatementEntry $entry,
        bool $isBank,
    ): void {
        $rule = $this->ruleMatcher->match($workspace, $entry->description);

        if (! is_array($rule)) {
            return;
        }

        $categoryId = $rule['action_type'] === FinancialTransactionType::Transfer->value
            ? null
            : $rule['category_id'];

        try {
            if ($isBank) {
                $this->entryActions->classifyBankEntry(
                    $workspace,
                    $entry,
                    $rule['payee_name'],
                    $categoryId,
                );

                return;
            }

            $this->entryActions->classifyCardEntry(
                $workspace,
                $entry,
                $rule['payee_name'],
                $categoryId,
            );
        } catch (ValidationException) {
            return;
        }
    }

    private function clearIgnored(BankStatementEntry|CardStatementEntry $entry): void
    {
        if (! $entry->is_ignored) {
            return;
        }

        $entry->update([
            'is_ignored' => false,
            'ignored_by' => null,
            'ignored_at' => null,
        ]);
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
     * @return Collection<int, AccountMovement>
     */
    private function unmatchedMovements(Workspace $workspace, int $accountId): Collection
    {
        return $workspace->accountMovements()
            ->where('financial_account_id', $accountId)
            ->where('is_reconciled', false)
            ->whereDoesntHave('bankStatementEntry')
            ->get();
    }
}
