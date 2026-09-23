<?php

namespace App\Services\Reconciliation;

use App\Enums\AccountMovementType;
use App\Enums\CategoryType;
use App\Enums\ExpenseRefundDestination;
use App\Enums\ExpenseRefundOrigin;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\CardStatementMaterializationService;
use App\Services\Finance\ClassificationRuleMatcher;
use App\Services\Finance\CreditCardInvoiceService;
use App\Services\Finance\ExpenseCategoryMatcher;
use App\Services\Finance\ExpenseRefundService;
use App\Services\Finance\FinancialEntryService;
use App\Services\Finance\TransferService;
use Illuminate\Support\Collection;
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
        private readonly CreditCardInvoiceService $invoiceService,
        private readonly ExpenseRefundService $refundService,
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

        if ($this->interpreter->isInvoicePayment($entry->description, $this->workspaceCardTokens($workspace))) {
            throw ValidationException::withMessages([
                'entry' => 'Pagamento de fatura não é uma nova despesa. Concilie com a liquidação da fatura.',
            ]);
        }

        $rule = $this->ruleMatcher->match(
            $workspace,
            $entry->description,
            $entry->financial_account_id,
        );

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
            $entry->financial_account_id,
        );

        if ($resolved['category_id'] === null) {
            throw ValidationException::withMessages([
                'category_id' => 'Defina uma categoria antes de conciliar este movimento.',
            ]);
        }

        return DB::transaction(function () use (
            $workspace,
            $entry,
            $user,
            $isExpense,
            $amount,
            $resolved,
        ): BankStatementEntry {
            $entry->update([
                'suggested_payee_name' => $this->nullableName($resolved['payee_name']),
                'suggested_category_id' => $resolved['category_id'],
            ]);
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

    public function reconcileRefund(
        Workspace $workspace,
        BankStatementEntry $entry,
        FinancialTransaction $transaction,
        User $user,
    ): BankStatementEntry {
        $this->assertSameWorkspace($workspace, $entry->workspace_id);
        $this->assertSameWorkspace($workspace, $transaction->workspace_id);
        $this->guardPendingBankEntry($entry);

        if ($this->interpreter->moneyToCents($entry->amount) <= 0) {
            throw ValidationException::withMessages([
                'financial_transaction_id' => 'Somente entradas bancárias podem ser vinculadas como reembolso.',
            ]);
        }

        return DB::transaction(function () use (
            $workspace,
            $entry,
            $transaction,
            $user,
        ): BankStatementEntry {
            $refund = $this->refundService->register(
                $workspace,
                $transaction,
                $user,
                [
                    'amount' => $this->interpreter->unsignedAmount($entry->amount),
                    'refunded_on' => $entry->occurred_on->toDateString(),
                    'destination_type' => ExpenseRefundDestination::Account->value,
                    'destination_account_id' => $entry->financial_account_id,
                    'credit_card_invoice_id' => null,
                    'notes' => 'Reembolso conciliado com movimento bancário importado: '.$entry->description,
                ],
                ExpenseRefundOrigin::BankReconciliation,
            );
            $movement = $refund->movement()->firstOrFail();

            $this->bankReconciliation->reconcile(
                $workspace,
                $entry->refresh(),
                $movement,
                $user,
            );
            $this->refundService->markLinked($refund, $user);

            return $entry->refresh();
        });
    }

    public function reconcileInvoicePayment(
        Workspace $workspace,
        BankStatementEntry $entry,
        CreditCardInvoice $invoice,
        User $user,
    ): BankStatementEntry {
        $this->assertSameWorkspace($workspace, $entry->workspace_id);
        $this->assertSameWorkspace($workspace, $invoice->workspace_id);
        $this->guardPendingBankEntry($entry);

        if (! $this->interpreter->isOutflow($entry->amount)) {
            throw ValidationException::withMessages([
                'credit_card_invoice_id' => 'Somente saídas bancárias podem liquidar uma fatura de cartão.',
            ]);
        }

        $amount = $this->interpreter->unsignedAmount($entry->amount);
        $paymentCents = abs($this->interpreter->moneyToCents($entry->amount));

        return DB::transaction(function () use (
            $workspace,
            $entry,
            $invoice,
            $user,
            $amount,
            $paymentCents,
        ): BankStatementEntry {
            $lockedInvoice = CreditCardInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($invoice->id)
                ->with('creditCard')
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->invoiceService->findCompatibleUnreconciledPayment(
                $lockedInvoice,
                $entry->financial_account_id,
                $amount,
                $entry->occurred_on->toDateString(),
            );

            if ($existing !== null) {
                $movement = $existing->movement()->firstOrFail();

                $this->bankReconciliation->reconcile(
                    $workspace,
                    $entry->refresh(),
                    $movement,
                    $user,
                );

                return $entry->refresh();
            }

            $outstandingCents = $this->invoiceService->outstandingCents($lockedInvoice);

            if ($paymentCents <= 0 || $paymentCents > $outstandingCents) {
                throw ValidationException::withMessages([
                    'credit_card_invoice_id' => 'Não há saldo em aberto compatível com este movimento bancário.',
                ]);
            }

            $this->invoiceService->pay(
                $lockedInvoice,
                [
                    'financial_account_id' => $entry->financial_account_id,
                    'paid_on' => $entry->occurred_on->toDateString(),
                    'amount' => $amount,
                    'payment_method' => $lockedInvoice->creditCard->invoice_payment_method->value,
                    'notes' => 'Pagamento conciliado com o movimento bancário importado.',
                ],
                allowOpen: true,
            );

            $movement = $lockedInvoice->payments()
                ->latest('id')
                ->firstOrFail()
                ->movement()
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

    public function reconcileCardPaymentWithoutInvoice(
        Workspace $workspace,
        BankStatementEntry $entry,
        CreditCard $card,
        User $user,
    ): BankStatementEntry {
        $this->assertSameWorkspace($workspace, $entry->workspace_id);
        $this->assertSameWorkspace($workspace, $card->workspace_id);
        $this->guardPendingBankEntry($entry);

        if (! $this->interpreter->isOutflow($entry->amount)) {
            throw ValidationException::withMessages([
                'credit_card_id' => 'Somente saídas bancárias podem ser registradas como pagamento de cartão.',
            ]);
        }

        $amount = $this->interpreter->unsignedAmount($entry->amount);

        return DB::transaction(function () use (
            $workspace,
            $entry,
            $card,
            $user,
            $amount,
        ): BankStatementEntry {
            $payment = $this->invoiceService->createPendingPayment(
                $card,
                [
                    'financial_account_id' => $entry->financial_account_id,
                    'paid_on' => $entry->occurred_on->toDateString(),
                    'amount' => $amount,
                    'payment_method' => $card->invoice_payment_method->value,
                    'notes' => 'Pagamento do cartão conciliado antes da identificação da fatura.',
                ],
            );
            $movement = $payment->movement()->firstOrFail();

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

        if ($this->interpreter->isInvoicePayment($entry->description, $this->workspaceCardTokens($workspace))) {
            throw ValidationException::withMessages([
                'entry' => 'Pagamento de fatura não é uma transferência entre contas próprias. Concilie com a liquidação da fatura.',
            ]);
        }

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

    public function reconcilePlannedBankEntry(
        Workspace $workspace,
        BankStatementEntry $entry,
        User $user,
        int $transactionId,
    ): BankStatementEntry {
        $this->guardPendingBankEntry($entry);
        $transaction = $workspace->financialTransactions()->find($transactionId);
        $scheduled = $transaction instanceof FinancialTransaction
            ? ($transaction->due_date ?? $transaction->transaction_date)
            : null;
        $signedAmount = $transaction instanceof FinancialTransaction
            ? $this->signedTransactionAmount($transaction)
            : null;
        $days = $scheduled === null
            ? null
            : (int) abs($entry->occurred_on->diffInDays($scheduled, false));

        if (
            ! $transaction instanceof FinancialTransaction
            || $transaction->status !== FinancialTransactionStatus::Planned
            || ! in_array($transaction->type, [
                FinancialTransactionType::Expense,
                FinancialTransactionType::Income,
            ], true)
            || $transaction->financial_account_id !== $entry->financial_account_id
            || $transaction->credit_card_id !== null
            || $signedAmount === null
            || $this->interpreter->moneyToCents($signedAmount) !== $this->interpreter->moneyToCents($entry->amount)
            || $days === null
            || $days > 180
        ) {
            throw ValidationException::withMessages([
                'financial_transaction_id' => 'Selecione um lançamento planejado desta conta, com o mesmo valor e data próxima.',
            ]);
        }

        return DB::transaction(function () use (
            $workspace,
            $entry,
            $user,
            $transaction,
        ): BankStatementEntry {
            $settled = $this->entryService->settle(
                $transaction,
                $entry->occurred_on->toDateString(),
            );
            $movement = $settled->accountMovements()
                ->where('financial_account_id', $entry->financial_account_id)
                ->firstOrFail();

            return $this->bankReconciliation->reconcile(
                $workspace,
                $entry->refresh(),
                $movement,
                $user,
            );
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
        ?int $financialAccountId = null,
    ): array {
        $rule = $this->ruleMatcher->match($workspace, $description, $financialAccountId);
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

    public function acceptsAutomaticBankLink(
        Workspace $workspace,
        BankStatementEntry $entry,
        AccountMovement $movement,
    ): bool {
        $movement->loadMissing('transaction');
        $transaction = $movement->transaction;

        if (! $transaction instanceof FinancialTransaction) {
            return true;
        }

        if (! in_array($transaction->type, [
            FinancialTransactionType::Expense,
            FinancialTransactionType::Income,
        ], true)) {
            return true;
        }

        if ($transaction->category_id !== null) {
            return true;
        }

        $resolved = $this->resolveClassification(
            $workspace,
            $entry->description,
            $transaction->type === FinancialTransactionType::Expense,
            $entry->suggested_category_id,
            $entry->suggested_payee_name,
            $entry->financial_account_id,
        );

        if ($resolved['category_id'] === null) {
            return false;
        }

        $transaction->update(['category_id' => $resolved['category_id']]);

        return true;
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
    private function signedTransactionAmount(FinancialTransaction $transaction): string
    {
        $amount = ltrim((string) $transaction->amount, '-');

        return $transaction->type === FinancialTransactionType::Expense
            ? '-'.$amount
            : $amount;
    }

    private function unmatchedMovements(Workspace $workspace, int $accountId): Collection
    {
        return $workspace->accountMovements()
            ->where('financial_account_id', $accountId)
            ->where('is_reconciled', false)
            ->whereDoesntHave('bankStatementEntry')
            ->with('transaction')
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

    /**
     * @return array<int, string>
     */
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
}
