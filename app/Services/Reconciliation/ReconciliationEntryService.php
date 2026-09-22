<?php

namespace App\Services\Reconciliation;

use App\Enums\AccountMovementType;
use App\Enums\CategoryType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\CardStatementMaterializationService;
use App\Services\Finance\ClassificationRuleMatcher;
use App\Services\Finance\ExpenseCategoryMatcher;
use App\Services\Finance\FinancialEntryService;
use App\Services\Finance\TransferService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReconciliationEntryService
{
    public function __construct(
        private readonly ImportedMovementInterpreter $interpreter,
        private readonly FinancialEntryService $entryService,
        private readonly TransferService $transferService,
        private readonly BankReconciliationService $bankReconciliation,
        private readonly BankReconciliationSuggestionService $bankSuggestions,
        private readonly CardStatementMaterializationService $cardMaterialization,
        private readonly CardStatementReconciliationSuggestionService $cardSuggestions,
        private readonly ExpenseCategoryMatcher $categoryMatcher,
        private readonly ClassificationRuleMatcher $ruleMatcher,
    ) {}

    public function ignoreBankEntry(
        Workspace $workspace,
        BankStatementEntry $entry,
        User $user,
    ): BankStatementEntry {
        $this->assertSameWorkspace($workspace, $entry->workspace_id);
        $this->guardPendingBankEntry($entry);

        $entry->update([
            'is_ignored' => true,
            'ignored_by' => $user->id,
            'ignored_at' => now(),
        ]);

        return $entry->refresh();
    }

    public function ignoreCardEntry(
        Workspace $workspace,
        CardStatementEntry $entry,
        User $user,
    ): CardStatementEntry {
        $this->assertSameWorkspace($workspace, $entry->workspace_id);
        $this->guardPendingCardEntry($entry);

        $entry->update([
            'is_ignored' => true,
            'ignored_by' => $user->id,
            'ignored_at' => now(),
        ]);

        return $entry->refresh();
    }

    public function classifyBankEntry(
        Workspace $workspace,
        BankStatementEntry $entry,
        ?string $payeeName,
        ?int $categoryId,
    ): BankStatementEntry {
        $category = $this->resolveCategory(
            $workspace,
            $categoryId,
            $this->interpreter->isOutflow($entry->amount)
                ? CategoryType::Expense
                : CategoryType::Income,
        );

        $entry->update([
            'suggested_payee_name' => $this->nullableName($payeeName),
            'suggested_category_id' => $category?->id,
        ]);

        $this->applyDraftToRelatedBank($entry->refresh());

        return $entry->refresh();
    }

    public function classifyCardEntry(
        Workspace $workspace,
        CardStatementEntry $entry,
        ?string $payeeName,
        ?int $categoryId,
    ): CardStatementEntry {
        $category = $this->resolveCategory(
            $workspace,
            $categoryId,
            CategoryType::Expense,
        );

        $entry->update([
            'suggested_payee_name' => $this->nullableName($payeeName),
            'suggested_category_id' => $category?->id,
        ]);

        $this->applyDraftToRelatedCard($entry->refresh());

        return $entry->refresh();
    }

    public function createBankTransaction(
        Workspace $workspace,
        BankStatementEntry $entry,
        User $user,
    ): BankStatementEntry {
        $this->guardPendingBankEntry($entry);

        if ($this->interpreter->isInvoicePayment($entry->description)) {
            throw ValidationException::withMessages([
                'entry' => 'Pagamento de fatura não é uma nova despesa. Concilie com a liquidação da fatura.',
            ]);
        }

        $rule = $this->ruleMatcher->match($workspace, $entry->description);

        if (is_array($rule) && $rule['action_type'] === FinancialTransactionType::Transfer->value) {
            if ($rule['counterpart_account_id'] === null) {
                throw ValidationException::withMessages([
                    'entry' => 'A regra de transferência precisa de uma conta própria. Atualize a regra ou use “Marcar como transferência”.',
                ]);
            }

            return $this->createBankTransfer(
                $workspace,
                $entry,
                $user,
                $rule['counterpart_account_id'],
            );
        }

        $classifiedByRule = is_array($rule) && in_array($rule['action_type'], [
            FinancialTransactionType::Expense->value,
            FinancialTransactionType::Income->value,
        ], true);

        if ($this->interpreter->isLikelyTransfer($entry->description) && ! $classifiedByRule) {
            throw ValidationException::withMessages([
                'entry' => 'Este movimento parece uma transferência entre contas próprias. Use “Marcar como transferência”.',
            ]);
        }

        $movements = $this->unmatchedMovements($workspace, $entry->financial_account_id);
        $candidates = $this->bankSuggestions->candidates($entry, $movements);

        if ($this->hasRelevantCandidate($candidates)) {
            throw ValidationException::withMessages([
                'entry' => 'Já existe um lançamento compatível. Concilie em vez de criar outro.',
            ]);
        }

        $cents = $this->interpreter->moneyToCents($entry->amount);

        if ($cents === 0) {
            throw ValidationException::withMessages([
                'entry' => 'Não é possível criar um lançamento com valor zero.',
            ]);
        }

        $isExpense = $cents < 0;
        $amount = $this->interpreter->unsignedAmount($entry->amount);
        $resolved = $this->resolveClassification(
            $workspace,
            $entry->description,
            $isExpense,
            $entry->suggested_category_id,
            $entry->suggested_payee_name,
        );

        return DB::transaction(function () use (
            $workspace,
            $entry,
            $user,
            $isExpense,
            $amount,
            $resolved,
        ): BankStatementEntry {
            $created = $this->entryService->create(
                $workspace,
                [
                    'type' => $isExpense
                        ? FinancialTransactionType::Expense->value
                        : FinancialTransactionType::Income->value,
                    'transaction_date' => $entry->occurred_on->toDateString(),
                    'competence_date' => $entry->occurred_on->toDateString(),
                    'description' => $entry->description,
                    'amount' => $amount,
                    'financial_account_id' => $entry->financial_account_id,
                    'credit_card_id' => null,
                    'category_id' => $resolved['category_id'],
                    'family_member_id' => null,
                    'payment_method' => PaymentMethod::Other->value,
                    'payee_name' => $resolved['payee_name'],
                    'payment_instructions' => null,
                    'due_date' => null,
                    'settled_on' => $entry->occurred_on->toDateString(),
                    'status' => FinancialTransactionStatus::Confirmed->value,
                    'notes' => 'Criado a partir da conciliação, sem duplicar o movimento importado.',
                ],
                FinancialTransactionOrigin::Ofx,
            );
            $movement = $created->accountMovements()
                ->whereIn('type', [
                    AccountMovementType::ExpensePayment,
                    AccountMovementType::IncomeReceipt,
                ])
                ->firstOrFail();

            $this->bankReconciliation->reconcile(
                $workspace,
                $entry->refresh(),
                $movement,
                $user,
            );

            return $entry->refresh();
        });
    }

    public function createBankTransfer(
        Workspace $workspace,
        BankStatementEntry $entry,
        User $user,
        int $counterpartAccountId,
    ): BankStatementEntry {
        $this->guardPendingBankEntry($entry);

        $counterpart = $workspace->financialAccounts()->find($counterpartAccountId);

        if (! $counterpart instanceof FinancialAccount) {
            throw ValidationException::withMessages([
                'counterpart_account_id' => 'Selecione uma conta própria válida.',
            ]);
        }

        if ($counterpart->id === $entry->financial_account_id) {
            throw ValidationException::withMessages([
                'counterpart_account_id' => 'A outra conta da transferência deve ser diferente da conta de origem.',
            ]);
        }

        $movements = $this->unmatchedMovements($workspace, $entry->financial_account_id);
        $transferCandidate = collect($this->bankSuggestions->candidates($entry, $movements))
            ->first(fn (array $candidate): bool => in_array($candidate['type'], [
                AccountMovementType::TransferOut->value,
                AccountMovementType::TransferIn->value,
            ], true));

        if (is_array($transferCandidate)) {
            throw ValidationException::withMessages([
                'entry' => 'Já existe uma transferência compatível. Concilie em vez de criar outra.',
            ]);
        }

        $cents = $this->interpreter->moneyToCents($entry->amount);

        if ($cents === 0) {
            throw ValidationException::withMessages([
                'entry' => 'Não é possível marcar um movimento com valor zero como transferência.',
            ]);
        }

        $isOutgoing = $cents < 0;
        $amount = $this->interpreter->unsignedAmount($entry->amount);

        return DB::transaction(function () use (
            $workspace,
            $entry,
            $user,
            $counterpart,
            $isOutgoing,
            $amount,
        ): BankStatementEntry {
            $transfer = $this->transferService->create($workspace, [
                'transaction_date' => $entry->occurred_on->toDateString(),
                'description' => $entry->suggested_payee_name ?: $entry->description,
                'amount' => $amount,
                'source_account_id' => $isOutgoing
                    ? $entry->financial_account_id
                    : $counterpart->id,
                'destination_account_id' => $isOutgoing
                    ? $counterpart->id
                    : $entry->financial_account_id,
                'status' => FinancialTransactionStatus::Confirmed->value,
                'notes' => 'Transferência entre contas próprias criada na conciliação.',
                'origin' => FinancialTransactionOrigin::Ofx,
            ]);
            $movement = $transfer->accountMovements()
                ->where('financial_account_id', $entry->financial_account_id)
                ->firstOrFail();

            $this->bankReconciliation->reconcile(
                $workspace,
                $entry->refresh(),
                $movement,
                $user,
            );

            return $entry->refresh();
        });
    }

    public function createCardTransaction(
        Workspace $workspace,
        CardStatementEntry $entry,
        User $user,
    ): CardStatementEntry {
        $this->guardPendingCardEntry($entry);

        if ($this->interpreter->isInvoicePayment($entry->description)) {
            throw ValidationException::withMessages([
                'entry' => 'Pagamento de fatura não é uma nova despesa.',
            ]);
        }

        $invoice = $entry->invoice()->firstOrFail();
        $installments = $invoice->installments()
            ->with(['transaction', 'cardStatementEntry'])
            ->get()
            ->filter(
                fn ($installment): bool => $installment->cardStatementEntry === null,
            )
            ->values();
        $candidates = $this->cardSuggestions->candidates($entry, $installments);

        if ($this->hasRelevantCandidate($candidates, 75)) {
            throw ValidationException::withMessages([
                'entry' => 'Já existe uma compra compatível nesta fatura. Concilie em vez de criar outra.',
            ]);
        }

        if ($invoice->status !== CreditCardInvoiceStatus::Open) {
            throw ValidationException::withMessages([
                'entry' => 'A fatura precisa estar aberta para criar a compra a partir desta linha.',
            ]);
        }

        $this->cardMaterialization->createFromPendingEntry(
            $workspace,
            $entry->creditCard,
            $invoice,
            $entry,
            $user,
            $entry->suggested_payee_name,
            $entry->suggested_category_id,
        );

        return $entry->refresh();
    }

    public function applyDraftToRelatedBank(BankStatementEntry $entry): void
    {
        $transaction = $entry->accountMovement?->transaction;

        if (! $transaction instanceof FinancialTransaction) {
            return;
        }

        $this->applyDraftToTransaction(
            $transaction,
            $entry->suggested_payee_name,
            $entry->suggested_category_id,
        );
    }

    public function applyDraftToRelatedCard(CardStatementEntry $entry): void
    {
        $transaction = $entry->transactionInstallment?->transaction;

        if (! $transaction instanceof FinancialTransaction) {
            return;
        }

        $this->applyDraftToTransaction(
            $transaction,
            $entry->suggested_payee_name,
            $entry->suggested_category_id,
        );
    }

    private function applyDraftToTransaction(
        FinancialTransaction $transaction,
        ?string $payeeName,
        ?int $categoryId,
    ): void {
        $updates = [];
        $payee = $this->nullableName($payeeName);

        if ($payee !== null) {
            $updates['payee_name'] = $payee;
        }

        if ($categoryId !== null) {
            $updates['category_id'] = $categoryId;
        }

        if ($updates !== []) {
            $transaction->update($updates);
        }
    }

    /**
     * @return array{category_id: int|null, payee_name: string}
     */
    private function resolveClassification(
        Workspace $workspace,
        string $description,
        bool $isExpense,
        ?int $suggestedCategoryId,
        ?string $suggestedPayeeName,
    ): array {
        $rule = $this->ruleMatcher->match($workspace, $description);
        $isTransferRule = is_array($rule)
            && $rule['action_type'] === FinancialTransactionType::Transfer->value;
        $payee = $this->nullableName($suggestedPayeeName)
            ?? (is_array($rule) ? $rule['payee_name'] : null)
            ?? $description;
        $categoryId = $suggestedCategoryId
            ?? ($isTransferRule ? null : (is_array($rule) ? $rule['category_id'] : null));

        if ($categoryId === null && $isExpense) {
            $categoryId = $this->categoryMatcher->match($workspace, $description);
        }

        return [
            'category_id' => $categoryId,
            'payee_name' => $payee,
        ];
    }

    private function assertSameWorkspace(Workspace $workspace, int $workspaceId): void
    {
        abort_unless($workspace->id === $workspaceId, 404);
    }

    private function guardPendingBankEntry(BankStatementEntry $entry): void
    {
        if ($entry->is_reconciled || $entry->account_movement_id !== null) {
            throw ValidationException::withMessages([
                'entry' => 'Este movimento já foi conciliado.',
            ]);
        }

        if ($entry->is_ignored) {
            throw ValidationException::withMessages([
                'entry' => 'Este movimento já foi ignorado.',
            ]);
        }
    }

    private function guardPendingCardEntry(CardStatementEntry $entry): void
    {
        if ($entry->is_reconciled || $entry->transaction_installment_id !== null) {
            throw ValidationException::withMessages([
                'entry' => 'Esta linha da fatura já foi conciliada.',
            ]);
        }

        if ($entry->is_ignored) {
            throw ValidationException::withMessages([
                'entry' => 'Esta linha já foi ignorada.',
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function hasRelevantCandidate(array $candidates, int $minimum = 72): bool
    {
        $first = $candidates[0] ?? null;

        return is_array($first) && (int) ($first['score'] ?? 0) >= $minimum;
    }

    /**
     * @return Collection<int, AccountMovement>
     */
    private function unmatchedMovements(Workspace $workspace, int $accountId)
    {
        return $workspace->accountMovements()
            ->where('financial_account_id', $accountId)
            ->where('is_reconciled', false)
            ->whereDoesntHave('bankStatementEntry')
            ->get();
    }

    private function resolveCategory(
        Workspace $workspace,
        ?int $categoryId,
        CategoryType $type,
    ): ?Category {
        if ($categoryId === null) {
            return null;
        }

        $category = $workspace->categories()
            ->whereKey($categoryId)
            ->where('type', $type->value)
            ->first();

        if (! $category instanceof Category) {
            throw ValidationException::withMessages([
                'category_id' => 'Selecione uma categoria válida para este tipo de movimento.',
            ]);
        }

        return $category;
    }

    private function nullableName(?string $name): ?string
    {
        $name = is_string($name) ? trim($name) : '';

        return $name === '' ? null : mb_substr($name, 0, 160);
    }
}
