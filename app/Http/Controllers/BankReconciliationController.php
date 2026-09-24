<?php

namespace App\Http\Controllers;

use App\Enums\AccountMovementType;
use App\Enums\CategoryType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionType;
use App\Http\Requests\ClassifyReconciliationEntryRequest;
use App\Http\Requests\StoreBankReconciliationRequest;
use App\Http\Requests\StoreReconciliationCardPaymentRequest;
use App\Http\Requests\StoreReconciliationInvoicePaymentRequest;
use App\Http\Requests\StoreReconciliationRefundRequest;
use App\Http\Requests\StoreReconciliationTransferRequest;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\ClassificationRuleMatcher;
use App\Services\Finance\ExpenseCategoryMatcher;
use App\Services\Reconciliation\BankReconciliationService;
use App\Services\Reconciliation\BankReconciliationSuggestionService;
use App\Services\Reconciliation\CardStatementReconciliationSuggestionService;
use App\Services\Reconciliation\ExpenseRefundSuggestionService;
use App\Services\Reconciliation\ImportedMovementInterpreter;
use App\Services\Reconciliation\ImportReconciliationReprocessor;
use App\Services\Reconciliation\InvoicePaymentSuggestionService;
use App\Services\Reconciliation\ReconciliationEntryService;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly BankReconciliationService $reconciliationService,
        private readonly BankReconciliationSuggestionService $suggestionService,
        private readonly InvoicePaymentSuggestionService $invoicePaymentSuggestion,
        private readonly CardStatementReconciliationSuggestionService $cardSuggestionService,
        private readonly ReconciliationEntryService $entryActions,
        private readonly ImportReconciliationReprocessor $importReprocessor,
        private readonly ImportedMovementInterpreter $interpreter,
        private readonly ExpenseRefundSuggestionService $refundSuggestionService,
        private readonly ExpenseCategoryMatcher $categoryMatcher,
        private readonly ClassificationRuleMatcher $ruleMatcher,
    ) {}

    /** @var array<string, array<string, mixed>> */
    private array $matcherSuggestions = [];

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $listing = $this->listing($request);
        $filters = $this->filters($listing);
        $scopeReady = $this->hasScope($filters);
        $bankEntries = $this->scopedBankEntries($workspace, $filters, $listing);
        $cardEntries = $this->scopedCardEntries($workspace, $filters, $listing);
        $unreconciledBank = $bankEntries->where('is_reconciled', false)->values();
        $movements = $unreconciledBank->isEmpty()
            ? collect()
            : $workspace->accountMovements()
                ->where('is_reconciled', false)
                ->whereDoesntHave('bankStatementEntry')
                ->with([
                    'account:id,name',
                    'invoicePayment.creditCard:id,name,institution,last_four,payment_account_id',
                    'invoicePayment.invoice.creditCard:id,name,institution,last_four,payment_account_id',
                    'transaction:id,description,type,status,payee_name,category_id,competence_date,financial_account_id,credit_card_id,source_account_id,destination_account_id',
                    'transaction.sourceAccount:id,name',
                    'transaction.destinationAccount:id,name',
                    'transaction.creditCard:id,name',
                    'transaction.category:id,name,parent_id',
                    'transaction.category.parent:id,name',
                ])
                ->orderByDesc('occurred_on')
                ->orderByDesc('id')
                ->limit(1000)
                ->get();
        $invoices = $unreconciledBank->isEmpty()
            ? collect()
            : $workspace->creditCardInvoices()
                ->with('creditCard:id,name,institution,last_four,payment_account_id,invoice_payment_method')
                ->whereIn('status', [
                    CreditCardInvoiceStatus::Open->value,
                    CreditCardInvoiceStatus::Closed->value,
                    CreditCardInvoiceStatus::Partial->value,
                ])
                ->get();
        $invoiceIds = $cardEntries
            ->where('is_reconciled', false)
            ->pluck('credit_card_invoice_id')
            ->unique()
            ->values();
        $installments = $invoiceIds->isEmpty()
            ? collect()
            : $workspace->transactionInstallments()
                ->whereIn('credit_card_invoice_id', $invoiceIds)
                ->whereDoesntHave('cardStatementEntry')
                ->with([
                    'transaction:id,description,transaction_date,type,payee_name,category_id,competence_date,credit_card_id',
                    'transaction.category:id,name,parent_id',
                    'transaction.category.parent:id,name',
                    'transaction.creditCard:id,name,last_four',
                ])
                ->get();
        $scoped = $this->markDuplicates(
            $bankEntries
                ->map(fn (BankStatementEntry $entry): array => $entry->is_reconciled
                    ? $this->bankHistoryData($entry)
                    : $this->pendingBankEntryData($entry, $movements, $invoices))
                ->concat($cardEntries->map(
                    fn (CardStatementEntry $entry): array => $entry->is_reconciled
                        ? $this->cardHistoryData($entry)
                        : $this->pendingCardEntryData($entry, $installments),
                )),
        );
        $scoped = $this->filterColumns($scoped, $filters);
        $viewCounts = $this->viewCounts($scoped);
        $entries = $listing->sortMapped(
            $this->filterView($scoped, $filters['view']),
            [
                'description' => fn (array $entry): string => $entry['description'],
                'date' => fn (array $entry): string => $entry['occurred_on'],
                'amount' => fn (array $entry): int => ListingQuery::moneyToCents($entry['amount']),
                'source' => fn (array $entry): string => $entry['source_name'],
                'type' => fn (array $entry): string => $this->entryTypeLabel($entry),
                'category' => fn (array $entry): string => $this->entryCategoryLabel($entry),
                'status' => fn (array $entry): int => $this->entryStatusRank($entry),
            ],
        )->all();

        return Inertia::render('reconciliation/index', [
            'entries' => $entries,
            'filters' => [
                ...$listing->toArray(),
                ...$filters,
            ],
            'scopeReady' => $scopeReady,
            'viewCounts' => $viewCounts,
            'accountOptions' => $workspace->financialAccounts()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (FinancialAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                ])
                ->all(),
            'cardOptions' => $workspace->creditCards()
                ->orderBy('name')
                ->get(['id', 'name', 'last_four'])
                ->map(fn (CreditCard $card): array => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'last_four' => $card->last_four,
                ])
                ->all(),
            'importOptions' => $this->importOptions($workspace),
            'counterpartAccountOptions' => $workspace->financialAccounts()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (FinancialAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                ])
                ->all(),
            'categoryOptions' => $this->categoryOptions($workspace),
            'pendingEntriesCount' => $viewCounts['pending'],
            'unmatchedMovementsCount' => $workspace->accountMovements()
                ->where('is_reconciled', false)
                ->whereDoesntHave('bankStatementEntry')
                ->count(),
            'reconciledEntriesCount' => $viewCounts['reconciled'],
            'ignoredEntriesCount' => $viewCounts['ignored'],
        ]);
    }

    public function reprocess(Request $request, int $import): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspace();
        $financialImport = $workspace->financialImports()->findOrFail($import);
        $this->importReprocessor->reprocess($workspace, $financialImport, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Importação reaberta e processada de novo, sem duplicar lançamentos.',
        ]);

        return to_route('reconciliation.index', [
            ...$this->filterQuery($request),
            'import' => $financialImport->id,
        ]);
    }

    public function bulkConfirmRules(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:250'],
            'entries.*' => ['integer', 'distinct'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspace();
        $confirmed = 0;
        $skipped = 0;

        foreach ($validated['entries'] as $entryId) {
            $entry = $workspace->bankStatementEntries()
                ->where('is_reconciled', false)
                ->where('is_ignored', false)
                ->find((int) $entryId);

            if (! $entry instanceof BankStatementEntry) {
                $skipped++;

                continue;
            }

            $rule = $this->ruleMatcher->match(
                $workspace,
                $entry->description,
                $entry->financial_account_id,
            );

            if (
                ! is_array($rule)
                || ! in_array($rule['action_type'], [
                    FinancialTransactionType::Expense->value,
                    FinancialTransactionType::Income->value,
                ], true)
                || $rule['category_id'] === null
            ) {
                $skipped++;

                continue;
            }

            try {
                $this->entryActions->classifyBankEntry(
                    $workspace,
                    $entry,
                    $rule['payee_name'],
                    $rule['category_id'],
                );
                $this->entryActions->createBankTransaction(
                    $workspace,
                    $entry->refresh(),
                    $user,
                );
                $confirmed++;
            } catch (\Illuminate\Validation\ValidationException) {
                $skipped++;
            }
        }

        Inertia::flash('toast', [
            'type' => $confirmed > 0 ? 'success' : 'warning',
            'message' => $confirmed > 0
                ? "{$confirmed} movimento(s) conciliado(s) pelas regras existentes."
                    .($skipped > 0 ? " {$skipped} ficaram para revisão." : '')
                : 'Nenhum movimento selecionado pôde ser conciliado automaticamente. Revise as exceções.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function bulkAdjust(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:1000'],
            'entries.*.kind' => ['required', 'string', 'in:statement,invoice'],
            'entries.*.id' => ['required', 'integer'],
            'action' => ['required', 'string', 'in:category,ignore'],
            'category_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspace();
        $action = $validated['action'];
        $categoryId = isset($validated['category_id'])
            ? (int) $validated['category_id']
            : null;

        if ($action === 'category' && $categoryId === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'category_id' => 'Selecione uma categoria para o ajuste em lote.',
            ]);
        }

        $updated = 0;
        $skipped = 0;

        foreach ($validated['entries'] as $selection) {
            try {
                if ($selection['kind'] === 'statement') {
                    $entry = $workspace->bankStatementEntries()
                        ->with('accountMovement.transaction')
                        ->find((int) $selection['id']);

                    if (! $entry instanceof BankStatementEntry) {
                        $skipped++;

                        continue;
                    }

                    if ($action === 'ignore') {
                        if ($entry->is_reconciled || $entry->is_ignored) {
                            $skipped++;

                            continue;
                        }

                        $this->entryActions->ignoreBankEntry($workspace, $entry, $user);
                    } else {
                        $this->entryActions->classifyBankEntry(
                            $workspace,
                            $entry,
                            $entry->suggested_payee_name
                                ?? $entry->accountMovement?->transaction?->payee_name,
                            $categoryId,
                        );
                    }
                } else {
                    $entry = $workspace->cardStatementEntries()
                        ->with('transactionInstallment.transaction')
                        ->find((int) $selection['id']);

                    if (! $entry instanceof CardStatementEntry) {
                        $skipped++;

                        continue;
                    }

                    if ($action === 'ignore') {
                        if ($entry->is_reconciled || $entry->is_ignored) {
                            $skipped++;

                            continue;
                        }

                        $this->entryActions->ignoreCardEntry($workspace, $entry, $user);
                    } else {
                        $this->entryActions->classifyCardEntry(
                            $workspace,
                            $entry,
                            $entry->suggested_payee_name
                                ?? $entry->transactionInstallment?->transaction?->payee_name,
                            $categoryId,
                        );
                    }
                }

                $updated++;
            } catch (\Illuminate\Validation\ValidationException) {
                $skipped++;
            }
        }

        Inertia::flash('toast', [
            'type' => $updated > 0 ? 'success' : 'warning',
            'message' => $updated > 0
                ? "{$updated} item(ns) ajustado(s) em lote."
                    .($skipped > 0 ? " {$skipped} ficaram sem alteração." : '')
                : 'Nenhum dos itens selecionados pôde ser ajustado.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function store(
        StoreBankReconciliationRequest $request,
        int $entry,
    ): RedirectResponse {
        $workspace = $this->workspace();
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $statementEntry = $this->findEntry($workspace, $entry);

        if ($request->filled('financial_transaction_id')) {
            $this->entryActions->reconcilePlannedBankEntry(
                $workspace,
                $statementEntry,
                $user,
                $request->integer('financial_transaction_id'),
            );

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Pré-agendamento conciliado na data do extrato, sem criar outro lançamento.',
            ]);

            return to_route('reconciliation.index', $this->filterQuery($request));
        }

        $movement = $workspace->accountMovements()
            ->findOrFail($request->integer('account_movement_id'));

        $this->reconciliationService->reconcile(
            $workspace,
            $statementEntry,
            $movement,
            $user,
        );
        $this->entryActions->applyDraftToRelatedBank($statementEntry->refresh()->load('accountMovement.transaction'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Movimento conciliado sem criar um novo lançamento.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function invoicePayment(
        StoreReconciliationInvoicePaymentRequest $request,
        int $entry,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspace();
        $statementEntry = $this->findEntry($workspace, $entry);
        $invoice = $workspace->creditCardInvoices()
            ->findOrFail($request->integer('credit_card_invoice_id'));

        $this->entryActions->reconcileInvoicePayment(
            $workspace,
            $statementEntry,
            $invoice,
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pagamento da fatura conciliado sem criar uma nova despesa.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function refundCandidates(int $entry): JsonResponse
    {
        $workspace = $this->workspace();
        $statementEntry = $this->findEntry($workspace, $entry);

        abort_if($statementEntry->is_reconciled || $statementEntry->is_ignored, 422);
        abort_if($this->interpreter->moneyToCents($statementEntry->amount) <= 0, 422);

        return response()->json([
            'candidates' => $this->refundSuggestionService->candidates(
                $workspace,
                $statementEntry,
            ),
        ]);
    }

    public function refund(
        StoreReconciliationRefundRequest $request,
        int $entry,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspace();
        $statementEntry = $this->findEntry($workspace, $entry);
        $transaction = $workspace->financialTransactions()
            ->findOrFail($request->integer('financial_transaction_id'));

        $this->entryActions->reconcileRefund(
            $workspace,
            $statementEntry,
            $transaction,
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Entrada vinculada como reembolso sem criar receita.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function cardPayment(
        StoreReconciliationCardPaymentRequest $request,
        int $entry,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspace();
        $statementEntry = $this->findEntry($workspace, $entry);
        $card = $workspace->creditCards()
            ->findOrFail($request->integer('credit_card_id'));

        $this->entryActions->reconcileCardPaymentWithoutInvoice(
            $workspace,
            $statementEntry,
            $card,
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pagamento do cartão conciliado. O vínculo com a fatura ficará pendente até ela ser identificada.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function destroy(Request $request, int $entry): RedirectResponse
    {
        $workspace = $this->workspace();
        $this->reconciliationService->undo(
            $workspace,
            $this->findEntry($workspace, $entry),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Conciliação desfeita. Os dois movimentos voltaram a ficar pendentes.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function ignore(Request $request, int $entry): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->entryActions->ignoreBankEntry(
            $this->workspace(),
            $this->findEntry($this->workspace(), $entry),
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Movimento ignorado. Ele não gera despesa nem receita.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function restoreIgnored(Request $request, int $entry): RedirectResponse
    {
        $this->entryActions->restoreBankEntry(
            $this->workspace(),
            $this->findEntry($this->workspace(), $entry),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Movimento restaurado para a conciliação.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function classify(
        ClassifyReconciliationEntryRequest $request,
        int $entry,
    ): RedirectResponse {
        $this->entryActions->classifyBankEntry(
            $this->workspace(),
            $this->findEntry($this->workspace(), $entry),
            $request->input('payee_name'),
            $request->filled('category_id') ? $request->integer('category_id') : null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Classificação atualizada sem alterar a origem do movimento.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function create(
        ClassifyReconciliationEntryRequest $request,
        int $entry,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspace();
        $model = $this->findEntry($workspace, $entry);

        if ($request->has('category_id') || $request->has('payee_name')) {
            $model = $this->entryActions->classifyBankEntry(
                $workspace,
                $model,
                $request->input('payee_name'),
                $request->filled('category_id')
                    ? $request->integer('category_id')
                    : $model->suggested_category_id,
            );
        }

        $this->entryActions->createBankTransaction(
            $workspace,
            $model,
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Lançamento criado e conciliado com o movimento importado.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    public function transfer(
        StoreReconciliationTransferRequest $request,
        int $entry,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->entryActions->createBankTransfer(
            $this->workspace(),
            $this->findEntry($this->workspace(), $entry),
            $user,
            $request->integer('counterpart_account_id'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Transferência entre contas próprias registrada, sem criar receita ou despesa.',
        ]);

        return to_route('reconciliation.index', $this->filterQuery($request));
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findEntry(Workspace $workspace, int $entry): BankStatementEntry
    {
        return $workspace->bankStatementEntries()->findOrFail($entry);
    }

    private function listing(Request $request): ListingQuery
    {
        return ListingQuery::from(
            $request,
            ['description', 'date', 'amount', 'source', 'type', 'category', 'status'],
            'date',
            'desc',
            [
                'kind',
                'account',
                'card',
                'view',
                'period',
                'from',
                'to',
                'import',
                'category',
                'flow',
                'entry_type',
            ],
        );
    }

    /**
     * @return array{
     *     kind: string,
     *     account: int|null,
     *     card: int|null,
     *     import: int|null,
     *     period: string|null,
     *     from: string|null,
     *     to: string|null,
     *     view: string
     * }
     */
    private function filters(ListingQuery $listing): array
    {
        $kind = $listing->filter('kind') ?? 'all';
        $view = $listing->filter('view') ?? 'all';
        $from = $this->normalizeDate($listing->filter('from'));
        $to = $this->normalizeDate($listing->filter('to'));
        $import = $listing->intFilter('import');
        $period = $this->normalizePeriod($listing->filter('period'));

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($import === null && $period === null && $from === null && $to === null) {
            $period = CarbonImmutable::today()->format('Y-m');
        }

        $category = $listing->filter('category');
        $flow = $listing->filter('flow') ?? 'all';
        $entryType = $listing->filter('entry_type') ?? 'all';

        return [
            'kind' => in_array($kind, ['statement', 'invoice'], true) ? $kind : 'all',
            'account' => $listing->intFilter('account'),
            'card' => $listing->intFilter('card'),
            'import' => $import,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'category' => $category !== null && ($category === 'none' || ctype_digit($category))
                ? $category
                : null,
            'flow' => in_array($flow, ['in', 'out'], true) ? $flow : 'all',
            'entry_type' => in_array($entryType, [
                'expense',
                'income',
                'transfer',
                'invoice_payment',
                'refund',
                'card_purchase',
            ], true) ? $entryType : 'all',
            'view' => in_array($view, [
                'all',
                'pending',
                'suggestions',
                'duplicates',
                'uncategorized',
                'transfers',
                'reconciled',
                'ignored',
            ], true) ? $view : 'all',
        ];
    }

    /** @return array<string, int|string> */
    private function filterQuery(Request $request): array
    {
        $listing = $this->listing($request);
        $query = [
            ...$listing->toArray(),
            ...$this->filters($listing),
        ];

        return array_filter(
            $query,
            function (int|string|null $value, string $key): bool {
                if ($value === null || $value === 'all' || $value === '') {
                    return false;
                }

                return ! (
                    ($key === 'sort' && $value === 'date')
                    || ($key === 'direction' && $value === 'desc')
                    || ($key === 'view' && $value === 'all')
                    || ($key === 'q' && $value === '')
                );
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array{
     *     kind: string,
     *     account: int|null,
     *     card: int|null,
     *     import: int|null,
     *     period: string|null,
     *     from: string|null,
     *     to: string|null,
     *     view: string
     * }  $filters
     */
    private function hasScope(array $filters): bool
    {
        if ($filters['import'] !== null) {
            return true;
        }

        return $filters['period'] !== null
            || ($filters['from'] !== null && $filters['to'] !== null);
    }

    /**
     * @param  array{
     *     kind: string,
     *     account: int|null,
     *     card: int|null,
     *     import: int|null,
     *     period: string|null,
     *     from: string|null,
     *     to: string|null,
     *     view: string
     * }  $filters
     * @return Collection<int, BankStatementEntry>
     */
    private function scopedBankEntries(
        Workspace $workspace,
        array $filters,
        ListingQuery $listing,
    ): Collection {
        if (! $this->hasScope($filters) || $filters['kind'] === 'invoice') {
            return collect();
        }

        $query = $workspace->bankStatementEntries()
            ->when(
                $filters['import'] !== null,
                fn ($query) => $query->where('financial_import_id', $filters['import']),
            )
            ->when(
                $filters['import'] === null && $filters['account'] !== null,
                fn ($query) => $query->where('financial_account_id', $filters['account']),
            );
        $this->applyDateRange($query, $filters, 'occurred_on');
        $query->with([
            'financialAccount:id,name',
            'financialImport:id,source_filename,type,metadata',
            'suggestedCategory:id,name,parent_id',
            'suggestedCategory.parent:id,name',
            'accountMovement.account:id,name',
            'accountMovement.invoicePayment.creditCard:id,name,institution,last_four,payment_account_id',
            'accountMovement.invoicePayment.invoice.creditCard:id,name,institution,last_four,payment_account_id',
            'accountMovement.transaction:id,description,type,payee_name,category_id,competence_date,financial_account_id,credit_card_id,source_account_id,destination_account_id',
            'accountMovement.transaction.sourceAccount:id,name',
            'accountMovement.transaction.destinationAccount:id,name',
            'accountMovement.transaction.creditCard:id,name',
            'accountMovement.transaction.category:id,name,parent_id',
            'accountMovement.transaction.category.parent:id,name',
            'reconciler:id,name',
        ]);
        $listing->applySearch($query, ['description']);

        return $query
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array{
     *     kind: string,
     *     account: int|null,
     *     card: int|null,
     *     import: int|null,
     *     period: string|null,
     *     from: string|null,
     *     to: string|null,
     *     view: string
     * }  $filters
     * @return Collection<int, CardStatementEntry>
     */
    private function scopedCardEntries(
        Workspace $workspace,
        array $filters,
        ListingQuery $listing,
    ): Collection {
        if (! $this->hasScope($filters) || $filters['kind'] === 'statement') {
            return collect();
        }

        $query = $workspace->cardStatementEntries()
            ->when(
                $filters['import'] !== null,
                fn ($query) => $query->where('financial_import_id', $filters['import']),
            )
            ->when(
                $filters['import'] === null && $filters['card'] !== null,
                fn ($query) => $query->where('credit_card_id', $filters['card']),
            );
        $this->applyDateRange($query, $filters, 'purchased_on');
        $query->with([
            'creditCard:id,name,last_four',
            'invoice:id,reference_month,credit_card_id',
            'financialImport:id,source_filename,type,metadata',
            'suggestedCategory:id,name,parent_id',
            'suggestedCategory.parent:id,name',
            'transactionInstallment.transaction:id,description,transaction_date,type,payee_name,category_id,competence_date,credit_card_id',
            'transactionInstallment.transaction.category:id,name,parent_id',
            'transactionInstallment.transaction.category.parent:id,name',
            'transactionInstallment.transaction.creditCard:id,name,last_four',
            'reconciler:id,name',
        ]);
        $listing->applySearch($query, ['description']);

        return $query
            ->orderByDesc('purchased_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, AccountMovement>  $movements
     * @param  Collection<int, CreditCardInvoice>  $invoices
     * @return array<string, mixed>
     */
    private function pendingBankEntryData(
        BankStatementEntry $entry,
        Collection $movements,
        Collection $invoices,
    ): array {
        $candidates = $entry->is_ignored
            ? []
            : $this->bankCandidates($entry, $movements, $invoices);
        $suggestion = collect($candidates)->firstWhere('is_suggestion', true);
        $matcher = $this->matcherSuggestion(
            $entry->description,
            $this->interpreter->isOutflow($entry->amount)
                ? CategoryType::Expense
                : CategoryType::Income,
            $entry->financial_account_id,
        );

        return $this->withFlags([
            ...$this->bankBaseData($entry),
            ...$this->classificationData($entry->suggested_payee_name, $entry->suggestedCategory),
            ...$this->relatedFromCandidate(is_array($suggestion) ? $suggestion : null),
            ...$this->matcherPayload($matcher),
            'candidates' => $candidates,
            'is_reconciled' => false,
            'is_ignored' => (bool) $entry->is_ignored,
            'reconciled_by_name' => null,
            'reconciled_at' => null,
        ], $candidates);
    }

    /**
     * @param  Collection<int, TransactionInstallment>  $installments
     * @return array<string, mixed>
     */
    private function pendingCardEntryData(
        CardStatementEntry $entry,
        Collection $installments,
    ): array {
        $candidates = $entry->is_ignored
            ? []
            : $this->cardCandidates($entry, $installments);
        $suggestion = collect($candidates)->firstWhere('is_suggestion', true);
        $matcher = $this->matcherSuggestion(
            $entry->description,
            CategoryType::Expense,
        );

        return $this->withFlags([
            ...$this->cardBaseData($entry),
            ...$this->classificationData($entry->suggested_payee_name, $entry->suggestedCategory),
            ...$this->relatedFromCandidate(is_array($suggestion) ? $suggestion : null),
            ...$this->matcherPayload($matcher),
            'candidates' => $candidates,
            'is_reconciled' => false,
            'is_ignored' => (bool) $entry->is_ignored,
            'reconciled_by_name' => null,
            'reconciled_at' => null,
        ], $candidates);
    }

    /** @return array<string, mixed> */
    private function bankHistoryData(BankStatementEntry $entry): array
    {
        $movement = $entry->accountMovement;
        $transaction = $movement?->transaction;
        $invoiceFields = $this->invoicePaymentSuggestion->fromMovement($movement);
        $related = $invoiceFields['is_invoice_payment']
            ? [
                ...$this->internalFromTransaction($transaction, $movement),
                ...$invoiceFields,
            ]
            : $this->internalFromTransaction($transaction, $movement);
        $matcher = $this->matcherSuggestion(
            $entry->description,
            $this->interpreter->isOutflow($entry->amount)
                ? CategoryType::Expense
                : CategoryType::Income,
            $entry->financial_account_id,
        );

        return $this->withFlags([
            ...$this->bankBaseData($entry),
            ...$this->classificationData(
                $entry->suggested_payee_name ?? $transaction?->payee_name,
                $entry->suggestedCategory ?? $transaction?->category,
            ),
            ...$related,
            ...$this->matcherPayload($matcher),
            'candidates' => [],
            'is_reconciled' => true,
            'is_ignored' => false,
            'reconciled_by_name' => $entry->reconciler?->name,
            'reconciled_at' => $entry->reconciled_at?->toIso8601String(),
        ], []);
    }

    /** @return array<string, mixed> */
    private function cardHistoryData(CardStatementEntry $entry): array
    {
        $installment = $entry->transactionInstallment;
        $transaction = $installment?->transaction;
        $related = $this->internalFromTransaction($transaction, null, $installment);
        $matcher = $this->matcherSuggestion(
            $entry->description,
            CategoryType::Expense,
        );

        return $this->withFlags([
            ...$this->cardBaseData($entry),
            ...$this->classificationData(
                $entry->suggested_payee_name ?? $transaction?->payee_name,
                $entry->suggestedCategory ?? $transaction?->category,
            ),
            ...$related,
            ...$this->matcherPayload($matcher),
            'candidates' => [],
            'is_reconciled' => true,
            'is_ignored' => false,
            'reconciled_by_name' => $entry->reconciler?->name,
            'reconciled_at' => $entry->reconciled_at?->toIso8601String(),
        ], []);
    }

    /** @return array<string, mixed> */
    private function bankBaseData(BankStatementEntry $entry): array
    {
        $accountName = $entry->financialAccount->name;

        return [
            'id' => $entry->id,
            'kind' => 'statement',
            'kind_label' => 'Movimento bancário',
            'source_format_label' => $this->interpreter->sourceFormatLabel($entry->financialImport, 'statement'),
            'source_name' => $accountName,
            'account_name' => $accountName,
            'card_name' => null,
            'financial_account_id' => $entry->financial_account_id,
            'credit_card_id' => null,
            'import_id' => $entry->financial_import_id,
            'import_filename' => $entry->financialImport?->source_filename,
            'invoice_id' => null,
            'invoice_label' => null,
            'invoice_payment_id' => null,
            'card_last_four' => null,
            'invoice_due_date' => null,
            'invoice_total_amount' => null,
            'invoice_paid_amount' => null,
            'invoice_outstanding_amount' => null,
            'invoice_status' => null,
            'invoice_status_label' => null,
            'is_invoice_payment' => false,
            'occurred_on' => $entry->occurred_on->toDateString(),
            'amount' => $entry->amount,
            'description' => $entry->description,
            'memo' => $entry->memo,
            'transaction_type' => $entry->transaction_type,
            'installment_label' => null,
            'relation_path' => "Movimento bancário → {$accountName}",
        ];
    }

    /** @return array<string, mixed> */
    private function cardBaseData(CardStatementEntry $entry): array
    {
        $cardName = "{$entry->creditCard->name} · final {$entry->creditCard->last_four}";
        $invoiceLabel = $entry->invoice !== null
            ? 'Fatura '.$entry->invoice->reference_month->format('m/Y')
            : 'Fatura';
        $installmentLabel = $entry->installment_number !== null && $entry->total_installments !== null
            ? "Parcela {$entry->installment_number}/{$entry->total_installments}"
            : null;

        return [
            'id' => $entry->id,
            'kind' => 'invoice',
            'kind_label' => 'Compra no cartão',
            'source_format_label' => $this->interpreter->sourceFormatLabel($entry->financialImport, 'invoice'),
            'source_name' => $cardName,
            'account_name' => $cardName,
            'card_name' => $cardName,
            'financial_account_id' => null,
            'credit_card_id' => $entry->credit_card_id,
            'import_id' => $entry->financial_import_id,
            'import_filename' => $entry->financialImport?->source_filename,
            'invoice_id' => $entry->credit_card_invoice_id,
            'invoice_label' => $invoiceLabel,
            'invoice_payment_id' => null,
            'card_last_four' => $entry->creditCard->last_four,
            'invoice_due_date' => null,
            'invoice_total_amount' => null,
            'invoice_paid_amount' => null,
            'invoice_outstanding_amount' => null,
            'invoice_status' => null,
            'invoice_status_label' => null,
            'is_invoice_payment' => false,
            'occurred_on' => $entry->purchased_on->toDateString(),
            'amount' => $entry->amount,
            'description' => $entry->description,
            'memo' => null,
            'transaction_type' => null,
            'installment_label' => $installmentLabel,
            'relation_path' => "Compra → {$cardName} → {$invoiceLabel}",
        ];
    }

    /**
     * @param  Collection<int, AccountMovement>  $movements
     * @param  Collection<int, CreditCardInvoice>  $invoices
     * @return array<int, array<string, mixed>>
     */
    private function bankCandidates(
        BankStatementEntry $entry,
        Collection $movements,
        Collection $invoices,
    ): array {
        $movementCandidates = collect($this->suggestionService->candidates($entry, $movements))
            ->map(function (array $candidate) use ($movements): array {
                $movement = ($candidate['movement_id'] ?? null) !== null
                    ? $movements->firstWhere('id', $candidate['movement_id'])
                    : null;
                $transaction = $movement?->transaction;

                if (
                    ! $transaction instanceof FinancialTransaction
                    && ($candidate['planned_transaction_id'] ?? null) !== null
                ) {
                    $transaction = $this->suggestionService->plannedTransaction(
                        (int) $candidate['planned_transaction_id'],
                    );
                }

                $mapped = [
                    ...$candidate,
                    'is_refund' => $movement?->type === AccountMovementType::Refund,
                    ...$this->internalFromTransaction($transaction, $movement),
                    ...$this->invoicePaymentSuggestion->fromMovement($movement),
                ];

                if (($candidate['is_planned'] ?? false) === true) {
                    $mapped['is_planned'] = true;
                    $mapped['transaction_id'] = $candidate['planned_transaction_id'];
                }

                return $mapped;
            });
        $claimedInvoiceIds = $movementCandidates
            ->pluck('invoice_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $invoiceCandidates = collect(
            $this->invoicePaymentSuggestion->candidates($entry, $invoices, $claimedInvoiceIds),
        );
        return $movementCandidates
            ->concat($invoiceCandidates)
            ->sort(function (array $left, array $right): int {
                return [$right['score'], $left['date_distance'], $right['invoice_id'] ?? $right['movement_id'] ?? $right['transaction_id'] ?? 0]
                    <=> [$left['score'], $right['date_distance'], $left['invoice_id'] ?? $left['movement_id'] ?? $left['transaction_id'] ?? 0];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TransactionInstallment>  $installments
     * @return list<array<string, mixed>>
     */
    private function cardCandidates(CardStatementEntry $entry, Collection $installments): array
    {
        return collect($this->cardSuggestionService->candidates($entry, $installments))
            ->map(function (array $candidate) use ($installments): array {
                $installment = $installments->firstWhere('id', $candidate['installment_id']);
                $transaction = $installment?->transaction;

                return [
                    'installment_id' => $candidate['installment_id'],
                    'occurred_on' => $candidate['transaction_date'],
                    'description' => $candidate['description'],
                    'amount' => $candidate['amount'],
                    'type' => 'installment',
                    'type_label' => "Parcela {$candidate['installment_number']}/{$candidate['total_installments']}",
                    'score' => $candidate['score'],
                    'confidence' => $candidate['confidence'],
                    'confidence_label' => $candidate['confidence_label'],
                    'date_distance' => $candidate['date_distance'],
                    'is_suggestion' => $candidate['is_suggestion'],
                    ...$this->internalFromTransaction($transaction, null, $installment),
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function internalFromTransaction(
        ?FinancialTransaction $transaction,
        ?AccountMovement $movement = null,
        ?TransactionInstallment $installment = null,
    ): array {
        $parts = $this->categoryParts($transaction?->category);
        $type = $movement?->type;
        $typeLabel = $type?->label();

        if ($installment instanceof TransactionInstallment) {
            $typeLabel = "Parcela {$installment->installment_number}/{$installment->total_installments}";
        }

        return [
            'related_transaction_id' => $transaction?->id,
            'related_description' => $transaction?->description ?? $movement?->description,
            'related_type' => $type?->value ?? $transaction?->type->value,
            'related_type_label' => $typeLabel ?? $transaction?->type->label(),
            'related_account_name' => $movement?->account?->name
                ?? $transaction?->account?->name
                ?? $transaction?->creditCard?->name,
            'related_counterpart_account_name' => $this->counterpartAccountName($transaction, $type),
            'related_competence_date' => $transaction?->competence_date?->toDateString()
                ?? $installment?->competence_month?->toDateString(),
            'related_payee_name' => $transaction?->payee_name,
            'related_category_id' => $parts['category_id'],
            'related_category_name' => $parts['category_name'],
            'related_parent_category_id' => $parts['parent_category_id'],
            'related_parent_category_name' => $parts['parent_category_name'],
            'related_subcategory_id' => $parts['subcategory_id'],
            'related_subcategory_name' => $parts['subcategory_name'],
            'related_is_transfer' => in_array($type, [
                AccountMovementType::TransferOut,
                AccountMovementType::TransferIn,
            ], true) || $transaction?->type->value === 'transfer',
        ];
    }

    private function counterpartAccountName(
        ?FinancialTransaction $transaction,
        ?AccountMovementType $type,
    ): ?string {
        if (! $transaction instanceof FinancialTransaction) {
            return null;
        }

        return match ($type) {
            AccountMovementType::TransferIn => $transaction->sourceAccount?->name,
            AccountMovementType::TransferOut => $transaction->destinationAccount?->name,
            default => $transaction->creditCard?->name,
        };
    }

    /**
     * @param  array<string, mixed>|null  $candidate
     * @return array<string, mixed>
     */
    private function relatedFromCandidate(?array $candidate): array
    {
        if ($candidate === null) {
            return [
                'related_transaction_id' => null,
                'related_description' => null,
                'related_type' => null,
                'related_type_label' => null,
                'related_account_name' => null,
                'related_counterpart_account_name' => null,
                'related_competence_date' => null,
                'related_payee_name' => null,
                'related_category_id' => null,
                'related_category_name' => null,
                'related_parent_category_id' => null,
                'related_parent_category_name' => null,
                'related_subcategory_id' => null,
                'related_subcategory_name' => null,
                'related_is_transfer' => false,
            ];
        }

        return [
            'related_transaction_id' => $candidate['related_transaction_id'] ?? null,
            'related_description' => $candidate['related_description'] ?? $candidate['description'] ?? null,
            'related_type' => $candidate['related_type'] ?? $candidate['type'] ?? null,
            'related_type_label' => $candidate['related_type_label'] ?? $candidate['type_label'] ?? null,
            'related_account_name' => $candidate['related_account_name'] ?? null,
            'related_counterpart_account_name' => $candidate['related_counterpart_account_name'] ?? null,
            'related_competence_date' => $candidate['related_competence_date'] ?? $candidate['occurred_on'] ?? null,
            'related_payee_name' => $candidate['related_payee_name'] ?? null,
            'related_category_id' => $candidate['related_category_id'] ?? null,
            'related_category_name' => $candidate['related_category_name'] ?? null,
            'related_parent_category_id' => $candidate['related_parent_category_id'] ?? null,
            'related_parent_category_name' => $candidate['related_parent_category_name'] ?? null,
            'related_subcategory_id' => $candidate['related_subcategory_id'] ?? null,
            'related_subcategory_name' => $candidate['related_subcategory_name'] ?? null,
            'related_is_transfer' => (bool) ($candidate['related_is_transfer'] ?? false),
            ...$this->invoiceFieldsFrom($candidate),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function invoiceFieldsFrom(array $candidate): array
    {
        if (! ($candidate['is_invoice_payment'] ?? false)) {
            return [
                'invoice_payment_id' => null,
                'invoice_due_date' => null,
                'invoice_total_amount' => null,
                'invoice_paid_amount' => null,
                'invoice_outstanding_amount' => null,
                'invoice_status' => null,
                'invoice_status_label' => null,
                'is_invoice_payment' => false,
            ];
        }

        return [
            'invoice_id' => $candidate['invoice_id'] ?? null,
            'invoice_payment_id' => $candidate['invoice_payment_id'] ?? null,
            'card_name' => $candidate['card_name'] ?? null,
            'card_last_four' => $candidate['card_last_four'] ?? null,
            'invoice_label' => $candidate['invoice_label'] ?? null,
            'invoice_due_date' => $candidate['invoice_due_date'] ?? null,
            'invoice_total_amount' => $candidate['invoice_total_amount'] ?? null,
            'invoice_paid_amount' => $candidate['invoice_paid_amount'] ?? null,
            'invoice_outstanding_amount' => $candidate['invoice_outstanding_amount'] ?? null,
            'invoice_status' => $candidate['invoice_status'] ?? null,
            'invoice_status_label' => $candidate['invoice_status_label'] ?? null,
            'is_invoice_payment' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classificationData(?string $payeeName, ?Category $category): array
    {
        $parts = $this->categoryParts($category);

        return [
            'payee_name' => $payeeName,
            'category_id' => $parts['category_id'],
            'category_name' => $parts['category_name'],
            'parent_category_id' => $parts['parent_category_id'],
            'parent_category_name' => $parts['parent_category_name'],
            'subcategory_id' => $parts['subcategory_id'],
            'subcategory_name' => $parts['subcategory_name'],
        ];
    }

    /**
     * @return array{
     *     category_id: int|null,
     *     category_name: string|null,
     *     parent_category_id: int|null,
     *     parent_category_name: string|null,
     *     subcategory_id: int|null,
     *     subcategory_name: string|null
     * }
     */
    private function categoryParts(?Category $category): array
    {
        if (! $category instanceof Category) {
            return [
                'category_id' => null,
                'category_name' => null,
                'parent_category_id' => null,
                'parent_category_name' => null,
                'subcategory_id' => null,
                'subcategory_name' => null,
            ];
        }

        if ($category->parent instanceof Category) {
            return [
                'category_id' => $category->id,
                'category_name' => $category->parent->name,
                'parent_category_id' => $category->parent->id,
                'parent_category_name' => $category->parent->name,
                'subcategory_id' => $category->id,
                'subcategory_name' => $category->name,
            ];
        }

        return [
            'category_id' => $category->id,
            'category_name' => $category->name,
            'parent_category_id' => $category->id,
            'parent_category_name' => $category->name,
            'subcategory_id' => null,
            'subcategory_name' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $matcher
     * @return array<string, mixed>
     */
    private function matcherPayload(array $matcher): array
    {
        return [
            'matcher_rule_id' => $matcher['rule_id'],
            'matcher_payee_name' => $matcher['payee_name'],
            'matcher_action_type' => $matcher['action_type'],
            'matcher_counterpart_account_id' => $matcher['counterpart_account_id'],
            'matcher_category_id' => $matcher['category_id'],
            'matcher_category_name' => $matcher['category_name'],
            'matcher_parent_category_id' => $matcher['parent_category_id'],
            'matcher_parent_category_name' => $matcher['parent_category_name'],
            'matcher_subcategory_id' => $matcher['subcategory_id'],
            'matcher_subcategory_name' => $matcher['subcategory_name'],
        ];
    }

    /**
     * @return array{
     *     rule_id: int|null,
     *     payee_name: string|null,
     *     action_type: string|null,
     *     counterpart_account_id: int|null,
     *     category_id: int|null,
     *     category_name: string|null,
     *     parent_category_id: int|null,
     *     parent_category_name: string|null,
     *     subcategory_id: int|null,
     *     subcategory_name: string|null
     * }
     */
    private function matcherSuggestion(
        string $description,
        CategoryType $type,
        ?int $financialAccountId = null,
    ): array {
        $cacheKey = $type->value.':'.($financialAccountId ?? 'none').':'.$description;

        if (array_key_exists($cacheKey, $this->matcherSuggestions)) {
            return $this->matcherSuggestions[$cacheKey];
        }

        $empty = [
            'rule_id' => null,
            'payee_name' => null,
            'action_type' => null,
            'counterpart_account_id' => null,
            ...$this->categoryParts(null),
        ];
        $workspace = $this->workspace();
        $rule = $this->ruleMatcher->match($workspace, $description, $financialAccountId);

        if (is_array($rule)) {
            $category = $rule['category_id'] !== null
                ? $workspace->categories()
                    ->with('parent:id,name')
                    ->find($rule['category_id'])
                : null;

            return $this->matcherSuggestions[$cacheKey] = [
                'rule_id' => $rule['rule_id'],
                'payee_name' => $rule['payee_name'],
                'action_type' => $rule['action_type'],
                'counterpart_account_id' => $rule['counterpart_account_id'],
                ...$this->categoryParts($category),
            ];
        }

        $categoryId = $type === CategoryType::Expense
            ? $this->categoryMatcher->match($workspace, $description)
            : null;

        if ($categoryId === null) {
            return $this->matcherSuggestions[$cacheKey] = $empty;
        }

        $category = $workspace->categories()
            ->with('parent:id,name')
            ->find($categoryId);

        return $this->matcherSuggestions[$cacheKey] = [
            'rule_id' => null,
            'payee_name' => null,
            'action_type' => $type->value,
            'counterpart_account_id' => null,
            ...$this->categoryParts($category),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function withFlags(array $entry, array $candidates): array
    {
        $hasSuggestion = collect($candidates)->contains(
            fn (array $candidate): bool => (bool) ($candidate['is_suggestion'] ?? false),
        );
        $best = collect($candidates)->firstWhere('is_suggestion', true);
        $isTransfer = (bool) ($entry['related_is_transfer'] ?? false)
            || $this->interpreter->isLikelyTransfer($entry['description']);
        $ruleAction = $entry['matcher_action_type'] ?? null;

        if ($ruleAction === FinancialTransactionType::Transfer->value) {
            $isTransfer = true;
        } elseif (in_array($ruleAction, [
            FinancialTransactionType::Expense->value,
            FinancialTransactionType::Income->value,
        ], true)) {
            $isTransfer = (bool) ($entry['related_is_transfer'] ?? false);
        }
        $isInvoicePayment = (bool) ($entry['is_invoice_payment'] ?? false)
            || $this->interpreter->isInvoicePayment($entry['description'], $this->workspaceCardTokens())
            || collect($candidates)->contains(
                fn (array $candidate): bool => (bool) ($candidate['is_invoice_payment'] ?? false)
                    && (bool) ($candidate['is_suggestion'] ?? false),
            );
        $isRefund = ($entry['related_type'] ?? null) === AccountMovementType::Refund->value
            || collect($candidates)->contains(
                fn (array $candidate): bool => (bool) ($candidate['is_refund'] ?? false)
                    && (bool) ($candidate['is_suggestion'] ?? false),
            );

        if ($isInvoicePayment || $isRefund) {
            $isTransfer = false;
        }

        $hasCategory = $entry['category_id'] !== null
            || $entry['related_category_id'] !== null;

        return [
            ...$entry,
            'has_suggestion' => $hasSuggestion,
            'is_likely_transfer' => $isTransfer,
            'is_likely_invoice_payment' => $isInvoicePayment,
            'is_likely_refund' => $isRefund,
            'is_uncategorized' => ! $hasCategory && ! $isTransfer && ! $isInvoicePayment && ! $isRefund,
            'is_possible_duplicate' => false,
            'suggestion_confidence' => is_array($best) ? ($best['confidence'] ?? null) : null,
            'suggestion_confidence_label' => is_array($best) ? ($best['confidence_label'] ?? null) : null,
            'suggestion_score' => is_array($best) ? ($best['score'] ?? null) : null,
            'suggestion_description' => is_array($best)
                ? ($best['related_description'] ?? $best['description'] ?? null)
                : null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function markDuplicates(Collection $entries): Collection
    {
        $indexed = $entries->values();
        $flags = array_fill(0, $indexed->count(), false);

        foreach ($indexed as $leftIndex => $left) {
            foreach ($indexed as $rightIndex => $right) {
                if ($rightIndex <= $leftIndex || $this->looksDuplicated($left, $right) === false) {
                    continue;
                }

                $flags[$leftIndex] = true;
                $flags[$rightIndex] = true;
            }
        }

        return $indexed->map(function (array $entry, int $index) use ($flags): array {
            $entry['is_possible_duplicate'] = $flags[$index] ?? false;

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function looksDuplicated(array $left, array $right): bool
    {
        if ($left['kind'] !== $right['kind'] || $left['account_name'] !== $right['account_name']) {
            return false;
        }

        if (ListingQuery::moneyToCents($left['amount']) !== ListingQuery::moneyToCents($right['amount'])) {
            return false;
        }

        $days = (int) abs(
            (new \DateTimeImmutable($left['occurred_on']))
                ->diff(new \DateTimeImmutable($right['occurred_on']))
                ->days,
        );

        return $days <= 1
            && $this->interpreter->descriptionSimilarity($left['description'], $right['description']) >= 0.8;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterColumns(Collection $entries, array $filters): Collection
    {
        return $entries
            ->filter(function (array $entry) use ($filters): bool {
                if ($filters['flow'] === 'out' && ListingQuery::moneyToCents($entry['amount']) >= 0) {
                    return false;
                }

                if ($filters['flow'] === 'in' && ListingQuery::moneyToCents($entry['amount']) <= 0) {
                    return false;
                }

                if (
                    $filters['entry_type'] !== 'all'
                    && $this->entryType($entry) !== $filters['entry_type']
                ) {
                    return false;
                }

                if ($filters['category'] === 'none') {
                    return $this->effectiveCategoryId($entry) === null;
                }

                if (
                    is_string($filters['category'])
                    && ctype_digit($filters['category'])
                    && $this->effectiveCategoryId($entry) !== (int) $filters['category']
                ) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function filterView(Collection $entries, string $view): Collection
    {
        return match ($view) {
            'pending' => $entries->filter(
                fn (array $entry): bool => ! $entry['is_reconciled'] && ! $entry['is_ignored'],
            )->values(),
            'suggestions' => $entries->filter(
                fn (array $entry): bool => $entry['has_suggestion']
                    && ! $entry['is_reconciled']
                    && ! $entry['is_ignored'],
            )->values(),
            'duplicates' => $entries->filter(fn (array $entry): bool => $entry['is_possible_duplicate'])->values(),
            'uncategorized' => $entries->filter(
                fn (array $entry): bool => $entry['is_uncategorized'] && ! $entry['is_ignored'],
            )->values(),
            'transfers' => $entries->filter(
                fn (array $entry): bool => $entry['is_likely_transfer']
                    && ! $entry['is_reconciled']
                    && ! $entry['is_ignored'],
            )->values(),
            'reconciled' => $entries->filter(fn (array $entry): bool => $entry['is_reconciled'])->values(),
            'ignored' => $entries->filter(fn (array $entry): bool => $entry['is_ignored'])->values(),
            default => $entries->values(),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $scoped
     * @return array<string, int>
     */
    private function viewCounts(Collection $scoped): array
    {
        return [
            'all' => $scoped->count(),
            'pending' => $scoped->filter(
                fn (array $entry): bool => ! $entry['is_reconciled'] && ! $entry['is_ignored'],
            )->count(),
            'suggestions' => $scoped->filter(
                fn (array $entry): bool => $entry['has_suggestion']
                    && ! $entry['is_reconciled']
                    && ! $entry['is_ignored'],
            )->count(),
            'duplicates' => $scoped->where('is_possible_duplicate', true)->count(),
            'uncategorized' => $scoped->filter(
                fn (array $entry): bool => $entry['is_uncategorized'] && ! $entry['is_ignored'],
            )->count(),
            'transfers' => $scoped->filter(
                fn (array $entry): bool => $entry['is_likely_transfer']
                    && ! $entry['is_reconciled']
                    && ! $entry['is_ignored'],
            )->count(),
            'reconciled' => $scoped->where('is_reconciled', true)->count(),
            'ignored' => $scoped->where('is_ignored', true)->count(),
        ];
    }

    /** @param array<string, mixed> $entry */
    private function effectiveCategoryId(array $entry): ?int
    {
        $value = $entry['category_id']
            ?? $entry['related_category_id']
            ?? $entry['matcher_category_id']
            ?? null;

        return $value === null ? null : (int) $value;
    }

    /** @param array<string, mixed> $entry */
    private function entryCategoryLabel(array $entry): string
    {
        return collect([
            $entry['parent_category_name']
                ?? $entry['related_parent_category_name']
                ?? $entry['matcher_parent_category_name']
                ?? $entry['category_name']
                ?? $entry['related_category_name']
                ?? $entry['matcher_category_name']
                ?? null,
            $entry['subcategory_name']
                ?? $entry['related_subcategory_name']
                ?? $entry['matcher_subcategory_name']
                ?? null,
        ])->filter()->unique()->implode(' / ');
    }

    /** @param array<string, mixed> $entry */
    private function entryType(array $entry): string
    {
        if ($entry['is_invoice_payment'] || $entry['is_likely_invoice_payment']) {
            return 'invoice_payment';
        }

        if ($entry['is_likely_refund']) {
            return 'refund';
        }

        if ($entry['related_is_transfer'] || $entry['is_likely_transfer']) {
            return 'transfer';
        }

        if ($entry['kind'] === 'invoice') {
            return 'card_purchase';
        }

        return ListingQuery::moneyToCents($entry['amount']) < 0 ? 'expense' : 'income';
    }

    /** @param array<string, mixed> $entry */
    private function entryTypeLabel(array $entry): string
    {
        return match ($this->entryType($entry)) {
            'invoice_payment' => 'Pagamento de fatura',
            'refund' => 'Reembolso',
            'transfer' => 'Transferência',
            'card_purchase' => 'Compra no cartão',
            'income' => 'Receita',
            default => 'Despesa',
        };
    }

    /** @param array<string, mixed> $entry */
    private function entryStatusRank(array $entry): int
    {
        return match (true) {
            $entry['is_ignored'] => 4,
            $entry['is_reconciled'] => 1,
            $entry['is_possible_duplicate'] => 3,
            $entry['has_suggestion'] => 2,
            default => 3,
        };
    }

    /**
     * @return list<array{id: int, name: string, parent_id: int|null, type: string, type_label: string}>
     */
    private function categoryOptions(Workspace $workspace): array
    {
        return $workspace->categories()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'type'])
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'type' => $category->type->value,
                'type_label' => $category->type->label(),
            ])
            ->all();
    }

    /**
     * @return list<array{
     *     id: int,
     *     filename: string|null,
     *     label: string,
     *     kind: string,
     *     kind_label: string,
     *     target: string,
     *     period: string|null,
     *     account_id: int|null,
     *     card_id: int|null
     * }>
     */
    private function importOptions(Workspace $workspace): array
    {
        return $workspace->financialImports()
            ->where('status', FinancialImportStatus::Completed)
            ->with([
                'financialAccount:id,name',
                'creditCard:id,name,last_four',
            ])
            ->latest('imported_at')
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(function (FinancialImport $import): array {
                $isInvoice = $import->type === FinancialImportType::CardStatement;
                $kindLabel = $isInvoice ? 'Fatura' : 'Extrato';
                $target = $isInvoice
                    ? ($import->creditCard instanceof CreditCard
                        ? "{$import->creditCard->name} · final {$import->creditCard->last_four}"
                        : 'Cartão')
                    : ($import->financialAccount?->name ?? 'Conta');
                $period = $import->statement_start_on !== null && $import->statement_end_on !== null
                    ? $import->statement_start_on->format('d/m/Y').'–'.$import->statement_end_on->format('d/m/Y')
                    : null;

                return [
                    'id' => $import->id,
                    'filename' => $import->source_filename,
                    'label' => collect([$kindLabel, $target, $period, $import->source_filename])
                        ->filter()
                        ->implode(' · '),
                    'kind' => $isInvoice ? 'invoice' : 'statement',
                    'kind_label' => $kindLabel,
                    'target' => $target,
                    'period' => $period,
                    'account_id' => $import->financial_account_id,
                    'card_id' => $import->credit_card_id,
                ];
            })
            ->all();
    }

    /**
     * @param  array{
     *     kind: string,
     *     account: int|null,
     *     card: int|null,
     *     import: int|null,
     *     period: string|null,
     *     from: string|null,
     *     to: string|null,
     *     view: string
     * }  $filters
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Eloquent\Relations\Relation<*, *, *>  $query
     */
    private function applyDateRange(mixed $query, array $filters, string $column): void
    {
        if ($filters['import'] !== null) {
            return;
        }

        $range = $this->dateRange($filters);

        if ($range === null) {
            return;
        }

        $query->whereBetween($column, [$range['start'], $range['end']]);
    }

    /**
     * @param  array{
     *     kind: string,
     *     account: int|null,
     *     card: int|null,
     *     import: int|null,
     *     period: string|null,
     *     from: string|null,
     *     to: string|null,
     *     view: string
     * }  $filters
     * @return array{start: string, end: string}|null
     */
    private function dateRange(array $filters): ?array
    {
        if ($filters['from'] !== null && $filters['to'] !== null) {
            return [
                'start' => $filters['from'],
                'end' => $filters['to'],
            ];
        }

        if ($filters['period'] === null) {
            return null;
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d', $filters['period'].'-01');

        if (! $start instanceof CarbonImmutable) {
            return null;
        }

        return [
            'start' => $start->toDateString(),
            'end' => $start->endOfMonth()->toDateString(),
        ];
    }

    private function normalizePeriod(?string $value): ?string
    {
        if ($value === null || preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', $value, $matches) !== 1) {
            return null;
        }

        $month = (int) $matches[2];

        if ($month < 1 || $month > 12) {
            return null;
        }

        return $matches[1].'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT);
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof CarbonImmutable ? $date->toDateString() : null;
    }

    /**
     * @return array<int, string>
     */
    private function workspaceCardTokens(): array
    {
        return $this->workspace()
            ->creditCards()
            ->get(['name', 'institution', 'last_four'])
            ->flatMap(fn (CreditCard $card): array => array_values(array_filter([
                $card->name,
                $card->institution,
                $card->last_four,
            ], fn (?string $token): bool => is_string($token) && trim($token) !== '')))
            ->values()
            ->all();
    }
}
