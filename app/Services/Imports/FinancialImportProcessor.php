<?php

namespace App\Services\Imports;

use App\Enums\ClassificationRuleAutomationLevel;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialTransactionType;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\CreditCardInvoice;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\CardStatementMaterializationService;
use App\Services\Finance\ClassificationRuleMatcher;
use App\Services\Reconciliation\BankReconciliationService;
use App\Services\Reconciliation\BankReconciliationSuggestionService;
use App\Services\Reconciliation\CardStatementReconciliationSuggestionService;
use App\Services\Reconciliation\ExpenseRefundSuggestionService;
use App\Services\Reconciliation\ImportedMovementInterpreter;
use App\Services\Reconciliation\InvoicePaymentSuggestionService;
use App\Services\Reconciliation\ReconciliationEntryService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FinancialImportProcessor
{
    public function __construct(
        private readonly BankReconciliationService $bankReconciliation,
        private readonly BankReconciliationSuggestionService $bankSuggestions,
        private readonly CardStatementReconciliationSuggestionService $cardSuggestions,
        private readonly CardStatementMaterializationService $cardMaterialization,
        private readonly ReconciliationEntryService $entryActions,
        private readonly ClassificationRuleMatcher $ruleMatcher,
        private readonly InvoicePaymentSuggestionService $invoicePaymentSuggestions,
        private readonly ExpenseRefundSuggestionService $refundSuggestions,
        private readonly ImportedMovementInterpreter $interpreter,
    ) {}

    /** @return array<string, int|string> */
    public function process(Workspace $workspace, FinancialImport $import, User $user): array
    {
        abort_unless($import->workspace_id === $workspace->id, 404);
        abort_unless($import->status === FinancialImportStatus::Completed, 404);

        $counters = [
            'matched_existing' => 0,
            'new_transactions_created' => 0,
            'transfers_identified' => 0,
            'invoice_payments_identified' => 0,
            'refunds_identified' => 0,
        ];
        $processedAny = false;

        $bankEntries = $import->bankStatementEntries()->orderBy('id')->get();
        $cardEntries = $import->cardStatementEntries()
            ->with(['creditCard', 'invoice'])
            ->orderBy('id')
            ->get();

        foreach ($bankEntries as $entry) {
            if ($entry->is_reconciled || $entry->is_ignored) {
                continue;
            }

            $processedAny = true;
            $this->processBankEntry($workspace, $entry, $user, $counters);
        }

        foreach ($cardEntries as $entry) {
            if ($entry->is_reconciled || $entry->is_ignored) {
                continue;
            }

            $processedAny = true;
            $this->processCardEntry($workspace, $entry, $user, $counters);
        }

        if (! $processedAny) {
            $previous = data_get($import->metadata, 'processing_summary');

            if (is_array($previous)) {
                foreach (array_keys($counters) as $key) {
                    $counters[$key] = (int) ($previous[$key] ?? 0);
                }
            }
        }

        return $this->refreshSummary($workspace, $import->refresh(), $counters);
    }

    /**
     * @param  array<string, int>|null  $actionCounters
     * @return array<string, int|string>
     */
    public function refreshSummary(
        Workspace $workspace,
        FinancialImport $import,
        ?array $actionCounters = null,
    ): array {
        abort_unless($import->workspace_id === $workspace->id, 404);

        $existing = data_get($import->metadata, 'processing_summary');
        $existing = is_array($existing) ? $existing : [];
        $counters = $actionCounters ?? [
            'matched_existing' => (int) ($existing['matched_existing'] ?? 0),
            'new_transactions_created' => (int) ($existing['new_transactions_created'] ?? 0),
            'transfers_identified' => (int) ($existing['transfers_identified'] ?? 0),
            'invoice_payments_identified' => (int) ($existing['invoice_payments_identified'] ?? 0),
            'refunds_identified' => (int) ($existing['refunds_identified'] ?? 0),
        ];

        $bankEntries = $import->bankStatementEntries()
            ->with('accountMovement.transaction')
            ->get();
        $cardEntries = $import->cardStatementEntries()
            ->with('transactionInstallment.transaction')
            ->get();
        $entries = $bankEntries->concat($cardEntries);
        $reconciled = $entries->filter(fn ($entry): bool => (bool) $entry->is_reconciled);
        $pendingConfirmation = $entries->filter(
            fn ($entry): bool => ! $entry->is_reconciled && ! $entry->is_ignored,
        )->count();
        $categorized = 0;
        $pendingCategorization = 0;

        foreach ($reconciled as $entry) {
            $transaction = $entry instanceof BankStatementEntry
                ? $entry->accountMovement?->transaction
                : $entry->transactionInstallment?->transaction;

            if (! $transaction instanceof FinancialTransaction) {
                continue;
            }

            if (! in_array($transaction->type, [
                FinancialTransactionType::Expense,
                FinancialTransactionType::Income,
            ], true)) {
                continue;
            }

            if ($transaction->category_id === null) {
                $pendingCategorization++;
            } else {
                $categorized++;
            }
        }

        $summary = [
            'items_imported' => (int) $import->total_records,
            'new_items' => (int) $import->imported_records,
            'automatically_reconciled' => $reconciled->count(),
            'matched_existing' => (int) ($counters['matched_existing'] ?? 0),
            'new_transactions_created' => (int) ($counters['new_transactions_created'] ?? 0),
            'transfers_identified' => (int) ($counters['transfers_identified'] ?? 0),
            'invoice_payments_identified' => (int) ($counters['invoice_payments_identified'] ?? 0),
            'refunds_identified' => (int) ($counters['refunds_identified'] ?? 0),
            'categorized_automatically' => $categorized,
            'pending_categorization' => $pendingCategorization,
            'pending_confirmation' => $pendingConfirmation,
            'duplicates_ignored' => (int) $import->duplicate_records,
            'remaining_exceptions' => $pendingCategorization + $pendingConfirmation,
            'processed_at' => now()->toIso8601String(),
        ];

        $metadata = $import->metadata ?? [];
        $metadata['processing_summary'] = $summary;
        $import->update(['metadata' => $metadata]);

        return $summary;
    }

    /** @param array<string, int> $counters */
    private function processBankEntry(
        Workspace $workspace,
        BankStatementEntry $entry,
        User $user,
        array &$counters,
    ): void {
        try {
            $this->clearRuleAutomation($entry);

            if ($this->tryInvoicePayment($workspace, $entry, $user)) {
                $counters['invoice_payments_identified']++;

                return;
            }

            if ($this->tryRefund($workspace, $entry, $user)) {
                $counters['refunds_identified']++;

                return;
            }

            $rule = $this->ruleMatcher->match(
                $workspace,
                $entry->description,
                $entry->financial_account_id,
            );

            if (is_array($rule)) {
                $this->processMatchedRule($workspace, $entry, $user, $rule, $counters);

                return;
            }

            $movements = $this->unmatchedMovements($workspace, $entry->financial_account_id);
            $candidates = $this->bankSuggestions->candidates($entry, $movements);

            if ($this->canAutoReconcile($candidates)) {
                $movement = $movements->firstWhere('id', $candidates[0]['movement_id']);

                if ($movement instanceof AccountMovement) {
                    if ($this->entryActions->acceptsAutomaticBankLink($workspace, $entry, $movement)) {
                        $this->bankReconciliation->reconcile($workspace, $entry, $movement, $user);
                        $counters['matched_existing']++;
                    }

                    return;
                }
            }

            if ($this->tryTransfer($workspace, $entry, $user)) {
                $counters['transfers_identified']++;
            }
        } catch (ValidationException) {
            // Ambiguidades ou exceções reais permanecem na caixa de entrada.
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array{
     *     rule_id: int,
     *     action_type: string,
     *     automation_level: string,
     *     payee_name: string|null,
     *     category_id: int|null,
     *     counterpart_account_id: int|null
     * }  $rule
     * @param  array<string, int>  $counters
     */
    private function processMatchedRule(
        Workspace $workspace,
        BankStatementEntry $entry,
        User $user,
        array $rule,
        array &$counters,
    ): void {
        $level = ClassificationRuleAutomationLevel::tryFrom($rule['automation_level'])
            ?? ClassificationRuleAutomationLevel::ClassifyOnly;

        $updates = [];

        if (is_string($rule['payee_name']) && trim($rule['payee_name']) !== '') {
            $updates['suggested_payee_name'] = trim($rule['payee_name']);
        }

        if (
            $rule['action_type'] !== FinancialTransactionType::Transfer->value
            && $rule['category_id'] !== null
        ) {
            $updates['suggested_category_id'] = $rule['category_id'];
        }

        if ($updates !== []) {
            $entry->update($updates);
            $entry->refresh();
        }

        if (! $level->canReconcile()) {
            $this->recordRuleAutomation(
                $entry,
                $rule['rule_id'],
                $level,
                'classified_pending',
                'A regra está configurada para somente classificar.',
            );

            return;
        }

        $movements = $this->unmatchedMovements($workspace, $entry->financial_account_id);
        $candidates = $this->bankSuggestions->candidates($entry, $movements);

        if ($this->canAutoReconcile($candidates)) {
            $movement = $movements->firstWhere('id', $candidates[0]['movement_id']);

            if (
                $movement instanceof AccountMovement
                && $this->entryActions->acceptsAutomaticBankLink($workspace, $entry, $movement)
            ) {
                $this->bankReconciliation->reconcile($workspace, $entry, $movement, $user);
                $counters['matched_existing']++;
                $this->recordRuleAutomation(
                    $entry->refresh(),
                    $rule['rule_id'],
                    $level,
                    'reconciled_existing',
                    'Um lançamento existente compatível foi encontrado e conciliado.',
                    (int) ($candidates[0]['score'] ?? 0),
                    'account_movement',
                    $movement->id,
                );

                return;
            }
        }

        if ($this->hasRelevantCandidate($candidates, 72)) {
            [$relatedType, $relatedId] = $this->candidateAuditRelation($candidates[0]);

            $this->recordRuleAutomation(
                $entry,
                $rule['rule_id'],
                $level,
                'pending_ambiguous_match',
                'Há candidato existente relevante, mas a correspondência não é segura o suficiente para concluir automaticamente.',
                (int) ($candidates[0]['score'] ?? 0),
                $relatedType,
                $relatedId,
            );

            return;
        }

        if (! $level->canCreate()) {
            $firstCandidate = $candidates[0] ?? null;

            if (is_array($firstCandidate)) {
                [$relatedType, $relatedId] = $this->candidateAuditRelation($firstCandidate);
                $this->recordRuleAutomation(
                    $entry,
                    $rule['rule_id'],
                    $level,
                    'pending_low_confidence',
                    'Foi encontrado um candidato, mas a confiança é insuficiente para conciliar automaticamente.',
                    (int) ($firstCandidate['score'] ?? 0),
                    $relatedType,
                    $relatedId,
                );
            } else {
                $this->recordRuleAutomation(
                    $entry,
                    $rule['rule_id'],
                    $level,
                    'pending_no_existing_match',
                    'Nenhum lançamento existente compatível foi encontrado e este nível não permite criar um novo.',
                );
            }

            return;
        }

        if ($rule['action_type'] === FinancialTransactionType::Transfer->value) {
            if ($rule['counterpart_account_id'] === null) {
                $this->recordRuleAutomation(
                    $entry,
                    $rule['rule_id'],
                    $level,
                    'pending_missing_counterpart',
                    'A regra de transferência não possui a outra conta definida.',
                );

                return;
            }

            $processed = $this->entryActions->createBankTransfer(
                $workspace,
                $entry,
                $user,
                $rule['counterpart_account_id'],
            );

            if ($processed->is_reconciled) {
                $counters['transfers_identified']++;
                $this->recordRuleAutomation(
                    $processed,
                    $rule['rule_id'],
                    $level,
                    'created_and_reconciled',
                    'Não havia lançamento existente compatível; a transferência foi criada pelo serviço do domínio e conciliada.',
                    null,
                    'account_movement',
                    $processed->account_movement_id,
                );
            }

            return;
        }

        if ($rule['category_id'] === null) {
            $this->recordRuleAutomation(
                $entry,
                $rule['rule_id'],
                $level,
                'pending_missing_category',
                'A regra não possui uma categoria válida para criar o lançamento.',
            );

            return;
        }

        $processed = $this->entryActions->createBankTransaction(
            $workspace,
            $entry->refresh(),
            $user,
        );

        if ($processed->is_reconciled) {
            $counters['new_transactions_created']++;
            $this->recordRuleAutomation(
                $processed,
                $rule['rule_id'],
                $level,
                'created_and_reconciled',
                'Não havia lançamento existente compatível; o lançamento foi criado pelo serviço do domínio e conciliado.',
                null,
                'account_movement',
                $processed->account_movement_id,
            );
        }
    }

    private function recordRuleAutomation(
        BankStatementEntry|CardStatementEntry $entry,
        int $ruleId,
        ClassificationRuleAutomationLevel $level,
        string $result,
        string $reason,
        ?int $score = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
    ): void {
        $entry->update([
            'matched_classification_rule_id' => $ruleId,
            'automation_level_applied' => $level->value,
            'automation_result' => $result,
            'automation_score' => $score,
            'automation_related_type' => $relatedType,
            'automation_related_id' => $relatedId,
            'automation_reason' => $reason,
            'automation_processed_at' => now(),
        ]);
    }

    private function clearRuleAutomation(
        BankStatementEntry|CardStatementEntry $entry,
    ): void {
        if (
            $entry->getAttribute('matched_classification_rule_id') === null
            && $entry->getAttribute('automation_result') === null
        ) {
            return;
        }

        $entry->update([
            'matched_classification_rule_id' => null,
            'automation_level_applied' => null,
            'automation_result' => null,
            'automation_score' => null,
            'automation_related_type' => null,
            'automation_related_id' => null,
            'automation_reason' => null,
            'automation_processed_at' => null,
        ]);
        $entry->refresh();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{0: string|null, 1: int|null}
     */
    private function candidateAuditRelation(array $candidate): array
    {
        foreach ([
            'movement_id' => 'account_movement',
            'planned_transaction_id' => 'financial_transaction',
            'transaction_id' => 'financial_transaction',
            'installment_id' => 'transaction_installment',
        ] as $key => $type) {
            $id = $candidate[$key] ?? null;

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                return [$type, (int) $id];
            }
        }

        return [null, null];
    }

    /** @param array<string, int> $counters */
    private function processCardEntry(
        Workspace $workspace,
        CardStatementEntry $entry,
        User $user,
        array &$counters,
    ): void {
        $invoice = $entry->invoice;

        if (
            ! $invoice instanceof CreditCardInvoice
            || $this->interpreter->moneyToCents($entry->amount) <= 0
        ) {
            return;
        }

        try {
            $this->clearRuleAutomation($entry);

            $availableInstallments = $invoice->installments()
                ->with(['transaction', 'cardStatementEntry'])
                ->get()
                ->filter(
                    fn (TransactionInstallment $installment): bool => $installment->cardStatementEntry === null,
                )
                ->values();
            $candidates = $this->cardSuggestions->candidates($entry, $availableInstallments);
            $matchesExisting = $this->canAutoReconcile($candidates);
            $hasRelevantCandidate = $this->hasRelevantCandidate($candidates, 75);
            $rule = $this->ruleMatcher->match($workspace, $entry->description);

            if (is_array($rule)) {
                $level = ClassificationRuleAutomationLevel::tryFrom($rule['automation_level'])
                    ?? ClassificationRuleAutomationLevel::ClassifyOnly;

                if ($rule['action_type'] !== FinancialTransactionType::Expense->value) {
                    $this->recordRuleAutomation(
                        $entry,
                        $rule['rule_id'],
                        $level,
                        'pending_incompatible_rule',
                        'Linhas de compra da fatura só podem usar regra do tipo despesa.',
                    );

                    return;
                }

                $updates = [];

                if (is_string($rule['payee_name']) && trim($rule['payee_name']) !== '') {
                    $updates['suggested_payee_name'] = trim($rule['payee_name']);
                }

                if ($rule['category_id'] !== null) {
                    $updates['suggested_category_id'] = $rule['category_id'];
                }

                if ($updates !== []) {
                    $entry->update($updates);
                    $entry->refresh();
                }

                if (! $level->canReconcile()) {
                    $this->recordRuleAutomation(
                        $entry,
                        $rule['rule_id'],
                        $level,
                        'classified_pending',
                        'A regra está configurada para somente classificar.',
                    );

                    return;
                }

                $this->cardMaterialization->materialize(
                    $workspace,
                    $entry->creditCard,
                    $invoice,
                    $entry,
                    $user,
                    requireClassification: true,
                    allowCreate: $level->canCreate(),
                );

                $entry->refresh();

                if ($entry->is_reconciled) {
                    if ($matchesExisting) {
                        [$relatedType, $relatedId] = $this->candidateAuditRelation($candidates[0]);
                        $counters['matched_existing']++;
                        $this->recordRuleAutomation(
                            $entry,
                            $rule['rule_id'],
                            $level,
                            'reconciled_existing',
                            'Uma compra ou parcela existente compatível foi encontrada e conciliada.',
                            (int) ($candidates[0]['score'] ?? 0),
                            $relatedType,
                            $relatedId,
                        );
                    } else {
                        $counters['new_transactions_created']++;
                        $this->recordRuleAutomation(
                            $entry,
                            $rule['rule_id'],
                            $level,
                            'created_and_reconciled',
                            'Não havia compra existente compatível; a compra foi criada pelo serviço do domínio e conciliada.',
                            null,
                            'transaction_installment',
                            $entry->transaction_installment_id,
                        );
                    }

                    return;
                }

                if ($hasRelevantCandidate) {
                    [$relatedType, $relatedId] = $this->candidateAuditRelation($candidates[0]);
                    $this->recordRuleAutomation(
                        $entry,
                        $rule['rule_id'],
                        $level,
                        'pending_ambiguous_match',
                        'Há compra ou parcela existente relevante, mas a correspondência não é segura o suficiente para concluir automaticamente.',
                        (int) ($candidates[0]['score'] ?? 0),
                        $relatedType,
                        $relatedId,
                    );

                    return;
                }

                if (! $level->canCreate()) {
                    $firstCandidate = $candidates[0] ?? null;

                    if (is_array($firstCandidate)) {
                        [$relatedType, $relatedId] = $this->candidateAuditRelation($firstCandidate);
                        $this->recordRuleAutomation(
                            $entry,
                            $rule['rule_id'],
                            $level,
                            'pending_low_confidence',
                            'Foi encontrada uma compra ou parcela candidata, mas a confiança é insuficiente para conciliar automaticamente.',
                            (int) ($firstCandidate['score'] ?? 0),
                            $relatedType,
                            $relatedId,
                        );
                    } else {
                        $this->recordRuleAutomation(
                            $entry,
                            $rule['rule_id'],
                            $level,
                            'pending_no_existing_match',
                            'Nenhuma compra ou parcela existente compatível foi encontrada e este nível não permite criar uma nova.',
                        );
                    }

                    return;
                }

                $this->recordRuleAutomation(
                    $entry,
                    $rule['rule_id'],
                    $level,
                    'pending_rule_not_applied',
                    'A regra permitia criar, mas a linha não pôde ser concluída automaticamente.',
                );

                return;
            }

            $this->cardMaterialization->materialize(
                $workspace,
                $entry->creditCard,
                $invoice,
                $entry,
                $user,
                requireClassification: true,
                allowCreate: false,
            );

            $entry->refresh();

            if ($entry->is_reconciled && $matchesExisting) {
                $counters['matched_existing']++;
            }
        } catch (ValidationException) {
            // Linhas ambíguas permanecem pendentes sem interromper a importação.
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function tryRefund(
        Workspace $workspace,
        BankStatementEntry $entry,
        User $user,
    ): bool {
        if (
            $this->interpreter->moneyToCents($entry->amount) <= 0
            || ! $this->interpreter->isLikelyRefund(
                $entry->description.' '.($entry->memo ?? ''),
            )
        ) {
            return false;
        }

        $candidates = $this->refundSuggestions->candidates($workspace, $entry);
        $first = $candidates[0] ?? null;

        if (! is_array($first) || (int) ($first['score'] ?? 0) < 90) {
            return false;
        }

        $second = $candidates[1] ?? null;

        if (
            is_array($second)
            && ((int) $first['score'] - (int) ($second['score'] ?? 0)) < 15
        ) {
            return false;
        }

        $transaction = $workspace->financialTransactions()
            ->find($first['transaction_id'] ?? null);

        if (! $transaction instanceof FinancialTransaction) {
            return false;
        }

        $this->entryActions->reconcileRefund(
            $workspace,
            $entry,
            $transaction,
            $user,
        );

        return $entry->refresh()->is_reconciled;
    }

    private function tryInvoicePayment(
        Workspace $workspace,
        BankStatementEntry $entry,
        User $user,
    ): bool {
        if (! $this->interpreter->isInvoicePayment(
            $entry->description,
            $this->workspaceCardTokens($workspace),
        )) {
            return false;
        }

        $invoices = $workspace->creditCardInvoices()
            ->with('creditCard')
            ->whereIn('status', [
                CreditCardInvoiceStatus::Open->value,
                CreditCardInvoiceStatus::Closed->value,
                CreditCardInvoiceStatus::Partial->value,
            ])
            ->get();
        $candidates = $this->invoicePaymentSuggestions->candidates($entry, $invoices);

        if ($this->canAutoReconcile($candidates, 85)) {
            $invoice = $invoices->firstWhere('id', $candidates[0]['invoice_id']);

            if ($invoice instanceof CreditCardInvoice) {
                $this->entryActions->reconcileInvoicePayment(
                    $workspace,
                    $entry,
                    $invoice,
                    $user,
                );

                return $entry->refresh()->is_reconciled;
            }
        }

        $cards = $workspace->creditCards()
            ->where('is_active', true)
            ->get([
                'id',
                'workspace_id',
                'name',
                'institution',
                'last_four',
                'payment_account_id',
                'invoice_payment_method',
            ]);
        $card = $this->invoicePaymentSuggestions->cardForEntry($entry, $cards);

        if ($card === null) {
            return false;
        }

        $this->entryActions->reconcileCardPaymentWithoutInvoice(
            $workspace,
            $entry,
            $card,
            $user,
        );

        return $entry->refresh()->is_reconciled;
    }

    private function tryTransfer(
        Workspace $workspace,
        BankStatementEntry $entry,
        User $user,
    ): bool {
        if (! $this->interpreter->isLikelyTransfer($entry->description, $entry->transaction_type)) {
            return false;
        }

        $counterpart = $this->findTransferCounterpart($workspace, $entry);

        if (! $counterpart instanceof BankStatementEntry) {
            return false;
        }

        $processed = $this->entryActions->createBankTransfer(
            $workspace,
            $entry,
            $user,
            $counterpart->financial_account_id,
        )->load('accountMovement.transaction.accountMovements');
        $transaction = $processed->accountMovement?->transaction;

        if (! $transaction instanceof FinancialTransaction) {
            return false;
        }

        $movement = $transaction->accountMovements
            ->first(fn (AccountMovement $movement): bool => $movement->financial_account_id === $counterpart->financial_account_id);

        if (! $movement instanceof AccountMovement) {
            return false;
        }

        $this->bankReconciliation->reconcile(
            $workspace,
            $counterpart->refresh(),
            $movement,
            $user,
        );

        if ($counterpart->financial_import_id !== $entry->financial_import_id) {
            $counterpartImport = $counterpart->financialImport()->first();

            if ($counterpartImport instanceof FinancialImport) {
                $this->refreshSummary($workspace, $counterpartImport);
            }
        }

        return true;
    }

    private function findTransferCounterpart(
        Workspace $workspace,
        BankStatementEntry $entry,
    ): ?BankStatementEntry {
        $cents = $this->interpreter->moneyToCents($entry->amount);

        if ($cents === 0) {
            return null;
        }

        $oppositeAmount = $this->centsToMoney(-$cents);
        $from = $entry->occurred_on->copy()->subDay()->toDateString();
        $to = $entry->occurred_on->copy()->addDay()->toDateString();
        $candidates = $workspace->bankStatementEntries()
            ->where('id', '!=', $entry->id)
            ->where('financial_account_id', '!=', $entry->financial_account_id)
            ->where('amount', $oppositeAmount)
            ->whereBetween('occurred_on', [$from, $to])
            ->where('is_reconciled', false)
            ->where('is_ignored', false)
            ->whereNull('account_movement_id')
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get()
            ->filter(fn (BankStatementEntry $candidate): bool => $this->interpreter->isLikelyTransfer(
                $candidate->description,
                $candidate->transaction_type,
            ))
            ->values();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function canAutoReconcile(array $candidates, int $minimumScore = 80): bool
    {
        $first = $candidates[0] ?? null;

        if (! is_array($first) || (int) ($first['score'] ?? 0) < $minimumScore) {
            return false;
        }

        $second = $candidates[1] ?? null;

        return ! is_array($second)
            || ((int) $first['score'] - (int) ($second['score'] ?? 0)) >= 10;
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function hasRelevantCandidate(array $candidates, int $minimum): bool
    {
        $first = $candidates[0] ?? null;

        return is_array($first) && (int) ($first['score'] ?? 0) >= $minimum;
    }

    /** @return Collection<int, AccountMovement> */
    private function unmatchedMovements(Workspace $workspace, int $accountId): Collection
    {
        return $workspace->accountMovements()
            ->where('financial_account_id', $accountId)
            ->where('is_reconciled', false)
            ->whereDoesntHave('bankStatementEntry')
            ->get();
    }

    /** @return array<int, string> */
    private function workspaceCardTokens(Workspace $workspace): array
    {
        return $workspace->creditCards()
            ->get(['name', 'institution', 'last_four'])
            ->flatMap(fn ($card): array => array_filter([
                $card->name,
                $card->institution,
                $card->last_four,
            ], fn (?string $token): bool => is_string($token) && trim($token) !== ''))
            ->values()
            ->all();
    }

    private function centsToMoney(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
