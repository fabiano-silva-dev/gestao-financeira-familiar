<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseRefundOrigin;
use App\Enums\ExpenseRefundStatus;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionInstallmentStatus;
use App\Http\Requests\SaveFinancialEntryRequest;
use App\Http\Requests\StoreExpenseRefundRequest;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\CardInstallmentPlanService;
use App\Services\Finance\ExpenseRefundService;
use App\Services\Finance\FinancialEntryService;
use App\Services\Finance\TransferService;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialTransactionController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly FinancialEntryService $entryService,
        private readonly TransferService $transferService,
        private readonly ExpenseRefundService $refundService,
        private readonly CardInstallmentPlanService $installmentPlanService,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $listing = ListingQuery::from(
            $request,
            ['description', 'date', 'category', 'status', 'amount'],
            'date',
            'desc',
            ['type', 'status', 'settlement', 'category', 'account', 'period', 'import', 'invoice'],
        );
        $query = $workspace
            ->financialTransactions()
            ->whereIn('financial_transactions.type', [
                FinancialTransactionType::Income,
                FinancialTransactionType::Expense,
                FinancialTransactionType::Transfer,
            ])
            ->with([
                'account:id,name',
                'creditCard:id,name,last_four',
                'category:id,name,parent_id',
                'category.parent:id,name',
                'familyMember:id,name',
                'sourceAccount:id,name',
                'destinationAccount:id,name',
                'refunds.destinationAccount:id,name',
                'refunds.creditCard:id,name,last_four',
                'refunds.invoice:id,reference_month',
                'refunds.movement.bankStatementEntry:id,account_movement_id,financial_import_id',
                'refunds.movement.bankStatementEntry.financialImport:id,source_filename',
                'refunds.creator:id,name',
                'refunds.linker:id,name',
                'recurrence' => fn ($query) => $query->withTrashed()->select('id', 'deleted_at'),
                'installments' => fn ($query) => $query
                    ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
                    ->orderBy('installment_number'),
            ])
            ->withCount('installments')
            ->select('financial_transactions.*');

        if ($listing->search !== '') {
            $term = $listing->searchTerm();
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('financial_transactions.description', 'ilike', $term)
                    ->orWhere('financial_transactions.payee_name', 'ilike', $term);
            });
        }

        $type = $listing->filter('type');

        if ($type !== null && FinancialTransactionType::tryFrom($type) !== null) {
            $query->where('financial_transactions.type', $type);
        }

        $status = $listing->filter('status');

        if ($status !== null && FinancialTransactionStatus::tryFrom($status) !== null) {
            $query->where('financial_transactions.status', $status);
        }

        if ($listing->filter('settlement') === 'settled') {
            $query->whereNotNull('financial_transactions.settled_on');
        } elseif ($listing->filter('settlement') === 'pending') {
            $query->whereNull('financial_transactions.settled_on');
        }

        $categoryFilter = $listing->filter('category');

        if ($categoryFilter === 'none') {
            $query->whereNull('financial_transactions.category_id');
        } else {
            $categoryId = $listing->intFilter('category');

            if ($categoryId !== null) {
                $childIds = $workspace->categories()
                    ->where('parent_id', $categoryId)
                    ->pluck('id');

                $query->where(function (Builder $inner) use ($categoryId, $childIds): void {
                    $inner->where('financial_transactions.category_id', $categoryId);

                    if ($childIds->isNotEmpty()) {
                        $inner->orWhereIn(
                            'financial_transactions.category_id',
                            $childIds,
                        );
                    }
                });
            }
        }

        $accountId = $listing->intFilter('account');

        if ($accountId !== null) {
            $query->where(function (Builder $inner) use ($accountId): void {
                $inner->where('financial_transactions.financial_account_id', $accountId)
                    ->orWhere('financial_transactions.source_account_id', $accountId)
                    ->orWhere('financial_transactions.destination_account_id', $accountId);
            });
        }

        $importId = $listing->intFilter('import');
        $importScope = null;

        if ($importId !== null) {
            $import = $workspace->financialImports()->find($importId);

            if ($import instanceof FinancialImport) {
                $importScope = [
                    'id' => $import->id,
                    'filename' => $import->source_filename,
                ];
                $query->where(function (Builder $inner) use ($importId): void {
                    $inner->whereHas(
                        'accountMovements.bankStatementEntry',
                        fn (Builder $entries) => $entries->where(
                            'financial_import_id',
                            $importId,
                        ),
                    )->orWhereHas(
                        'installments.cardStatementEntry',
                        fn (Builder $entries) => $entries->where(
                            'financial_import_id',
                            $importId,
                        ),
                    );
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $invoiceId = $listing->intFilter('invoice');

        if ($invoiceId !== null) {
            $invoice = $workspace->creditCardInvoices()->find($invoiceId);

            if ($invoice instanceof CreditCardInvoice) {
                $query->whereHas(
                    'installments',
                    fn (Builder $installments) => $installments
                        ->where('credit_card_invoice_id', $invoice->id)
                        ->where(
                            'status',
                            '!=',
                            TransactionInstallmentStatus::Cancelled->value,
                        ),
                );
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $monthStart = $this->periodStart($listing->filter('period'));
        $monthStartDate = $monthStart->toDateString();
        $monthEndDate = $monthStart->endOfMonth()->toDateString();
        $query->where(function (Builder $period) use ($monthStartDate, $monthEndDate): void {
            $period->where(function (Builder $byInstallment) use ($monthStartDate, $monthEndDate): void {
                $byInstallment
                    ->where(
                        'financial_transactions.type',
                        FinancialTransactionType::Expense->value,
                    )
                    ->whereHas(
                        'installments',
                        function (Builder $installments) use ($monthStartDate, $monthEndDate): void {
                            $installments
                                ->where(
                                    'status',
                                    '!=',
                                    TransactionInstallmentStatus::Cancelled->value,
                                )
                                ->whereBetween('competence_month', [
                                    $monthStartDate,
                                    $monthEndDate,
                                ]);
                        },
                    );
            })->orWhere(function (Builder $byTransactionDate) use ($monthStartDate, $monthEndDate): void {
                $byTransactionDate
                    ->where(function (Builder $withoutActiveInstallments): void {
                        $withoutActiveInstallments
                            ->where(
                                'financial_transactions.type',
                                '!=',
                                FinancialTransactionType::Expense->value,
                            )
                            ->orWhereDoesntHave(
                                'installments',
                                function (Builder $installments): void {
                                    $installments->where(
                                        'status',
                                        '!=',
                                        TransactionInstallmentStatus::Cancelled->value,
                                    );
                                },
                            );
                    })
                    ->whereDate('financial_transactions.transaction_date', '>=', $monthStartDate)
                    ->whereDate('financial_transactions.transaction_date', '<=', $monthEndDate);
            });
        });

        $listing->applySort($query, [
            'description' => 'financial_transactions.description',
            'date' => 'financial_transactions.transaction_date',
            'category' => function (Builder $query, string $direction): void {
                $query->leftJoin(
                    'categories',
                    'categories.id',
                    '=',
                    'financial_transactions.category_id',
                )->orderBy('categories.name', $direction);
            },
            'status' => 'financial_transactions.status',
            'amount' => 'financial_transactions.amount',
        ], 'financial_transactions.id');

        return Inertia::render('transactions/index', [
            'entries' => $query
                ->get()
                ->map(function (FinancialTransaction $entry) use ($monthStartDate, $monthEndDate): array {
                    $period = $this->periodAmount($entry, $monthStartDate, $monthEndDate);
                    $entry->unsetRelation('installments');

                    return [
                        ...$this->entryData($entry),
                        ...$period,
                    ];
                }),
            'filters' => [
                ...$listing->toArray(),
                'period' => $monthStart->format('Y-m'),
            ],
            'importScope' => $importScope,
            'hasRecords' => $workspace->financialTransactions()
                ->whereIn('type', [
                    FinancialTransactionType::Income,
                    FinancialTransactionType::Expense,
                    FinancialTransactionType::Transfer,
                ])
                ->exists(),
            'typeOptions' => FinancialTransactionType::options(),
            'statusOptions' => FinancialTransactionStatus::options(),
            'settlementOptions' => [
                ['value' => 'settled', 'label' => 'Liquidado'],
                ['value' => 'pending', 'label' => 'Pendente'],
            ],
            'categoryOptions' => $workspace->categories()
                ->with('parent:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category): array => [
                    'value' => (string) $category->id,
                    'label' => $category->parent === null
                        ? $category->name
                        : "{$category->parent->name} / {$category->name}",
                ])
                ->push([
                    'value' => 'none',
                    'label' => 'Sem categoria',
                ]),
            'accountOptions' => $workspace->financialAccounts()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (FinancialAccount $account): array => [
                    'value' => (string) $account->id,
                    'label' => $account->name,
                ]),
        ]);
    }

    public function createExpense(Request $request): Response
    {
        return $this->createResponse($request, FinancialTransactionType::Expense);
    }

    public function createIncome(Request $request): Response
    {
        return $this->createResponse($request, FinancialTransactionType::Income);
    }

    public function createTransfer(Request $request): Response
    {
        return $this->createResponse($request, FinancialTransactionType::Transfer);
    }

    public function store(SaveFinancialEntryRequest $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $entry = $this->entryService->create(
            $workspace,
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $entry->type === FinancialTransactionType::Expense
                ? 'Despesa cadastrada com sucesso.'
                : 'Receita cadastrada com sucesso.',
        ]);

        return $this->accountReturn($request, $workspace)
            ?? to_route('transactions.index');
    }

    public function edit(int $entry): Response
    {
        $financialEntry = $this->findEntry($entry);

        return Inertia::render('transactions/edit', [
            'entry' => $this->entryData($financialEntry),
            'installmentPlan' => $this->installmentPlanService->summary($financialEntry),
            'typeOptions' => FinancialTransactionType::options(),
            'refundInvoiceOptions' => $financialEntry->credit_card_id === null
                ? []
                : $this->workspace()->creditCardInvoices()
                    ->where('credit_card_id', $financialEntry->credit_card_id)
                    ->orderByDesc('reference_month')
                    ->get(['id', 'reference_month'])
                    ->map(fn ($invoice): array => [
                        'id' => $invoice->id,
                        'label' => 'Fatura '.$invoice->reference_month->format('m/Y'),
                    ])
                    ->all(),
            ...$this->referenceOptions(),
        ]);
    }

    public function update(
        SaveFinancialEntryRequest $request,
        int $entry,
    ): RedirectResponse {
        $financialEntry = $this->findEntry($entry);

        $this->entryService->update(
            $financialEntry,
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Lançamento atualizado com sucesso.',
        ]);

        return to_route('transactions.index');
    }

    public function mergeInstallmentPlan(int $entry): RedirectResponse
    {
        $financialEntry = $this->findEntry($entry);
        $canonical = $this->installmentPlanService->merge(
            $this->workspace(),
            $financialEntry,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Compra parcelada unificada. As próximas faturas seguem esta compra.',
        ]);

        return to_route('transactions.edit', $canonical);
    }

    public function advanceStatus(int $entry): RedirectResponse
    {
        $financialEntry = $this->findEntry($entry);
        $financialEntry = $financialEntry->type === FinancialTransactionType::Transfer
            ? $this->transferService->advanceStatus($financialEntry)
            : $this->entryService->advanceStatus($financialEntry);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($financialEntry->status) {
                FinancialTransactionStatus::Confirmed => 'Lançamento confirmado com sucesso.',
                FinancialTransactionStatus::Cancelled => 'Lançamento cancelado com sucesso.',
                FinancialTransactionStatus::Planned => 'Lançamento marcado como planejado.',
            },
        ]);

        return to_route('transactions.index');
    }

    public function refund(
        StoreExpenseRefundRequest $request,
        int $entry,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $financialEntry = $this->findEntry($entry);

        $this->refundService->register(
            $this->workspace(),
            $financialEntry,
            $user,
            $request->validated(),
            ExpenseRefundOrigin::Manual,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Reembolso registrado sem alterar o valor original da despesa.',
        ]);

        return to_route('transactions.edit', $entry);
    }

    public function toggleSettlement(int $entry): RedirectResponse
    {
        $financialEntry = $this->findEntry($entry);
        abort_if($financialEntry->type === FinancialTransactionType::Transfer, 404);
        $financialEntry = $this->entryService->toggleSettlement($financialEntry);
        $isExpense = $financialEntry->type === FinancialTransactionType::Expense;

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $financialEntry->settled_on !== null
                ? ($isExpense
                    ? 'Pagamento registrado com sucesso.'
                    : 'Recebimento registrado com sucesso.')
                : ($isExpense
                    ? 'Pagamento desfeito com sucesso.'
                    : 'Recebimento desfeito com sucesso.'),
        ]);

        return to_route('transactions.index');
    }

    public function revertRecurrenceSettlement(int $entry): RedirectResponse
    {
        $financialEntry = $this->findEntry($entry);
        $this->entryService->revertRecurrenceSettlement($financialEntry);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Lançamento excluído. A ocorrência da recorrência ficou pendente.',
        ]);

        return to_route('transactions.edit', $entry);
    }

    public function destroyRecurrenceOccurrence(int $entry): RedirectResponse
    {
        $financialEntry = $this->findEntry($entry);
        $this->entryService->deleteRecurrenceOccurrence($financialEntry);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Ocorrência excluída. Esta data não será gerada novamente pela recorrência.',
        ]);

        return to_route('transactions.index');
    }

    private function createResponse(Request $request, FinancialTransactionType $type): Response
    {
        $workspace = $this->workspace();
        $requestedAccountId = $request->integer('account');
        $defaultAccount = $requestedAccountId > 0
            ? $workspace->financialAccounts()->find($requestedAccountId)
            : null;
        $requestedPeriod = $request->query('period');
        $period = is_string($requestedPeriod)
            && preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', $requestedPeriod) === 1
                ? $this->periodStart($requestedPeriod)
                : null;
        $defaultDate = CarbonImmutable::today();

        if ($period !== null) {
            $defaultDate = $period->day(min(
                $defaultDate->day,
                $period->endOfMonth()->day,
            ));
        }

        return Inertia::render('transactions/create', [
            'entryType' => $type->value,
            'entryTypeLabel' => $type->label(),
            'defaultDate' => $defaultDate->toDateString(),
            'defaultAccountId' => $defaultAccount === null
                ? null
                : (string) $defaultAccount->id,
            'returnAccountId' => $defaultAccount === null
                ? null
                : (string) $defaultAccount->id,
            'returnPeriod' => $defaultAccount !== null && $period !== null
                ? $period->format('Y-m')
                : null,
            ...$this->referenceOptions($type),
        ]);
    }

    private function accountReturn(
        Request $request,
        Workspace $workspace,
    ): ?RedirectResponse {
        $accountId = $request->integer('_return_account');
        $period = $request->input('_return_period');

        if (
            $accountId <= 0
            || ! is_string($period)
            || preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1
            || ! $workspace->financialAccounts()->whereKey($accountId)->exists()
        ) {
            return null;
        }

        return redirect()->route('accounts.show', [
            'account' => $accountId,
            'period' => $period,
        ]);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findEntry(int $entry): FinancialTransaction
    {
        return $this->workspace()
            ->financialTransactions()
            ->whereIn('type', [
                FinancialTransactionType::Income,
                FinancialTransactionType::Expense,
                FinancialTransactionType::Transfer,
            ])
            ->with([
                'account:id,name',
                'creditCard:id,name,last_four',
                'category:id,name,parent_id',
                'category.parent:id,name',
                'familyMember:id,name',
                'sourceAccount:id,name',
                'destinationAccount:id,name',
                'accountMovements.bankStatementEntry.financialImport',
                'accountMovements.bankStatementEntry.financialAccount:id,name',
                'installments.cardStatementEntry.financialImport',
                'installments.cardStatementEntry.creditCard:id,name,last_four',
                'installments.cardStatementEntry.invoice:id,reference_month',
                'installments.invoice:id,reference_month',
                'refunds.destinationAccount:id,name',
                'refunds.creditCard:id,name,last_four',
                'refunds.invoice:id,reference_month',
                'refunds.movement.bankStatementEntry.financialImport',
                'refunds.creator:id,name',
                'refunds.linker:id,name',
                'recurrence' => fn ($query) => $query->withTrashed()->select('id', 'deleted_at'),
            ])
            ->withCount('installments')
            ->findOrFail($entry);
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceOptions(?FinancialTransactionType $categoryType = null): array
    {
        $workspace = $this->workspace();

        return [
            'accountOptions' => $workspace->financialAccounts()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (FinancialAccount $account): array => $this->referenceData($account))
                ->all(),
            'cardOptions' => $workspace->creditCards()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (CreditCard $card): array => [
                    ...$this->referenceData($card),
                    'label' => "{$card->name} · final {$card->last_four}",
                ])
                ->all(),
            'categoryOptions' => $this->categoryOptions($workspace, $categoryType),
            'memberOptions' => $workspace->familyMembers()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (FamilyMember $member): array => $this->referenceData($member))
                ->all(),
            'paymentMethods' => PaymentMethod::options(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, is_active: bool, type: string, label: string}>
     */
    private function categoryOptions(
        Workspace $workspace,
        ?FinancialTransactionType $categoryType,
    ): array {
        if ($categoryType === FinancialTransactionType::Transfer) {
            return [];
        }

        $query = $workspace->categories()
            ->with('parent:id,name')
            ->orderBy('name');

        if ($categoryType !== null) {
            $query->where('type', $categoryType->value);
        }

        return $query
            ->get()
            ->map(fn (Category $category): array => [
                ...$this->referenceData($category),
                'type' => $category->type->value,
                'label' => $category->parent === null
                    ? $category->name
                    : "{$category->parent->name} / {$category->name}",
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, is_active: bool}
     */
    private function referenceData(
        FinancialAccount|CreditCard|Category|FamilyMember $model,
    ): array {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'is_active' => $model->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryData(FinancialTransaction $entry): array
    {
        $categoryName = $entry->category?->name;

        if ($entry->category?->parent !== null) {
            $categoryName = "{$entry->category->parent->name} / {$entry->category->name}";
        }

        $refundSummary = $entry->type === FinancialTransactionType::Expense
            ? $this->refundService->summary($entry)
            : [
                'original_amount' => $entry->amount,
                'refunded_amount' => '0.00',
                'refundable_amount' => '0.00',
                'net_amount' => $entry->amount,
                'refund_status' => 'none',
                'refund_status_label' => 'Sem reembolso',
            ];
        $installmentCount = max(
            1,
            (int) ($entry->getAttribute('installments_count') ?? 0),
        );
        $settledOn = $entry->settled_on?->toDateString();
        $displayAmount = $entry->amount;

        if (
            in_array(
                $entry->type,
                [
                    FinancialTransactionType::Expense,
                    FinancialTransactionType::Income,
                ],
                true,
            )
            && $entry->credit_card_id === null
            && $installmentCount > 1
            && $entry->relationLoaded('installments')
        ) {
            $firstInstallment = $entry->installments
                ->whereNull('credit_card_invoice_id')
                ->sortBy('installment_number')
                ->first();

            $settledOn = $firstInstallment?->status === TransactionInstallmentStatus::Paid
                ? $firstInstallment->paid_at?->toDateString()
                : null;
            $displayAmount = $firstInstallment?->amount ?? $entry->amount;
        }

        return [
            'id' => $entry->id,
            'type' => $entry->type->value,
            'type_label' => $entry->type->label(),
            'transaction_date' => $entry->transaction_date->toDateString(),
            'competence_date' => $entry->competence_date?->toDateString()
                ?? $entry->transaction_date->toDateString(),
            'description' => $entry->description,
            'amount' => $displayAmount,
            ...$refundSummary,
            'refunds' => $entry->relationLoaded('refunds')
                ? $entry->refunds
                    ->sortByDesc('refunded_on')
                    ->values()
                    ->map(fn ($refund): array => [
                        'id' => $refund->id,
                        'amount' => $refund->amount,
                        'refunded_on' => $refund->refunded_on->toDateString(),
                        'destination_type' => $refund->destination_type->value,
                        'destination_label' => $refund->destination_type->value === 'account'
                            ? ($refund->destinationAccount?->name ?? 'Conta financeira')
                            : collect([
                                $refund->creditCard?->name,
                                $refund->invoice?->reference_month?->format('m/Y'),
                            ])->filter()->join(' · '),
                        'status' => $refund->status->value,
                        'status_label' => $refund->status->label(),
                        'origin' => $refund->origin->value,
                        'origin_label' => $refund->origin->label(),
                        'notes' => $refund->notes,
                        'created_by_name' => $refund->creator?->name,
                        'linked_by_name' => $refund->linker?->name,
                        'linked_at' => $refund->linked_at?->toIso8601String(),
                        'movement_reconciled' => (bool) $refund->movement?->is_reconciled,
                        'movement_import_filename' => $refund->movement?->bankStatementEntry?->financialImport?->source_filename,
                    ])
                    ->all()
                : [],
            'financial_account_id' => $entry->financial_account_id,
            'financial_account_name' => $entry->account?->name,
            'credit_card_id' => $entry->credit_card_id,
            'credit_card_name' => $entry->creditCard === null
                ? null
                : "{$entry->creditCard->name} · final {$entry->creditCard->last_four}",
            'installment_count' => $installmentCount,
            'category_id' => $entry->category_id,
            'category_name' => $categoryName,
            'family_member_id' => $entry->family_member_id,
            'family_member_name' => $entry->familyMember?->name,
            'source_account_id' => $entry->source_account_id,
            'source_account_name' => $entry->sourceAccount?->name,
            'destination_account_id' => $entry->destination_account_id,
            'destination_account_name' => $entry->destinationAccount?->name,
            'payment_method' => $entry->payment_method?->value,
            'payment_method_label' => $entry->payment_method?->label(),
            'payee_name' => $entry->payee_name,
            'payment_instructions' => $entry->payment_instructions,
            'due_date' => $entry->due_date?->toDateString(),
            'settled_on' => $settledOn,
            'is_settled' => $settledOn !== null,
            'status' => $entry->status->value,
            'status_label' => $this->entryStatusLabel($entry),
            'notes' => $entry->notes,
            'origin' => $entry->origin->value,
            'origin_label' => $entry->origin->label(),
            'origin_source' => $this->originSource($entry),
            'financial_recurrence_id' => $entry->financial_recurrence_id,
            'recurrence_was_removed' => $entry->financial_recurrence_id !== null
                && ($entry->recurrence === null || $entry->recurrence->trashed()),
            'recurrence_is_overridden' => $entry->recurrence_is_overridden,
        ];
    }

    /**
     * @return array{
     *     kind: string,
     *     label: string,
     *     filename: string|null,
     *     target_name: string|null,
     *     invoice_month: string|null,
     *     summary: string|null,
     *     href: string|null
     * }
     */
    private function originSource(FinancialTransaction $entry): array
    {
        $kind = $entry->origin->sourceKind();
        $bankEntry = $this->relatedBankStatementEntry($entry);
        $cardEntry = $this->relatedCardStatementEntry($entry);
        $filename = $bankEntry?->financialImport?->source_filename
            ?? $cardEntry?->financialImport?->source_filename;
        $targetName = $this->originTargetName($entry, $bankEntry, $cardEntry, $kind);
        $invoiceMonth = $cardEntry?->invoice?->reference_month
            ?? ($entry->relationLoaded('installments')
                ? $entry->installments->first()?->invoice?->reference_month
                : null);
        $invoiceMonthValue = $invoiceMonth?->toDateString();
        $summary = $this->originSummary(
            $entry->origin,
            $filename,
            $targetName,
            $invoiceMonth === null ? null : $invoiceMonth->format('m/Y'),
        );

        return [
            'kind' => $kind,
            'label' => $entry->origin->label(),
            'filename' => $filename,
            'target_name' => $targetName,
            'invoice_month' => $invoiceMonthValue,
            'summary' => $summary,
            'href' => $this->originHref($entry, $bankEntry, $cardEntry),
        ];
    }

    private function originHref(
        FinancialTransaction $entry,
        ?BankStatementEntry $bankEntry,
        ?CardStatementEntry $cardEntry,
    ): ?string {
        if ($cardEntry?->credit_card_invoice_id !== null) {
            return route('credit-card-invoices.show', [
                'invoice' => $cardEntry->credit_card_invoice_id,
                'linha' => $cardEntry->id,
            ], false);
        }

        $invoiceId = $entry->relationLoaded('installments')
            ? $entry->installments
                ->first(fn ($installment) => $installment->credit_card_invoice_id !== null)
                ?->credit_card_invoice_id
            : null;

        if ($invoiceId !== null) {
            return route('credit-card-invoices.show', $invoiceId, false);
        }

        if ($bankEntry?->financial_account_id !== null) {
            return route('reconciliation.index', [
                'account' => $bankEntry->financial_account_id,
                'period' => $bankEntry->occurred_on->format('Y-m'),
            ], false);
        }

        return null;
    }

    private function relatedBankStatementEntry(FinancialTransaction $entry): ?BankStatementEntry
    {
        if (! $entry->relationLoaded('accountMovements')) {
            return null;
        }

        foreach ($entry->accountMovements as $movement) {
            if (! $movement->relationLoaded('bankStatementEntry')) {
                continue;
            }

            $statement = $movement->bankStatementEntry;

            if ($statement instanceof BankStatementEntry) {
                return $statement;
            }
        }

        return null;
    }

    private function relatedCardStatementEntry(FinancialTransaction $entry): ?CardStatementEntry
    {
        if (! $entry->relationLoaded('installments')) {
            return null;
        }

        foreach ($entry->installments as $installment) {
            if (! $installment->relationLoaded('cardStatementEntry')) {
                continue;
            }

            $statement = $installment->cardStatementEntry;

            if ($statement instanceof CardStatementEntry) {
                return $statement;
            }
        }

        return null;
    }

    private function originTargetName(
        FinancialTransaction $entry,
        ?BankStatementEntry $bankEntry,
        ?CardStatementEntry $cardEntry,
        string $kind,
    ): ?string {
        if ($bankEntry?->financialAccount !== null) {
            return $bankEntry->financialAccount->name;
        }

        $card = $cardEntry?->creditCard ?? $entry->creditCard;

        if ($kind === 'card_statement' && $card !== null) {
            return "{$card->name} · final {$card->last_four}";
        }

        if ($kind === 'bank_statement') {
            return $entry->account?->name
                ?? $entry->sourceAccount?->name
                ?? $entry->destinationAccount?->name;
        }

        return null;
    }

    private function originSummary(
        FinancialTransactionOrigin $origin,
        ?string $filename,
        ?string $targetName,
        ?string $invoiceMonth,
    ): ?string {
        if ($origin === FinancialTransactionOrigin::Manual && $filename !== null) {
            return $targetName === null
                ? "Conciliado com o arquivo {$filename}."
                : "Conciliado com o arquivo {$filename} de {$targetName}.";
        }

        $parts = array_values(array_filter([
            $filename === null ? null : "Arquivo {$filename}",
            $targetName,
            $invoiceMonth === null ? null : "Competência {$invoiceMonth}",
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function entryStatusLabel(FinancialTransaction $entry): string
    {
        if (
            $entry->type !== FinancialTransactionType::Transfer
            && $entry->credit_card_id === null
            && $entry->settled_on !== null
        ) {
            return $entry->type === FinancialTransactionType::Expense
                ? 'Pago'
                : 'Recebido';
        }

        if (
            $entry->status !== FinancialTransactionStatus::Cancelled
            && $entry->settled_on === null
            && $entry->due_date !== null
            && $entry->due_date->lt(now()->startOfDay())
        ) {
            return 'Vencido';
        }

        return $entry->status->label();
    }

    /**
     * @return array{period_amount: string|null, period_note: string|null}
     */
    private function periodAmount(
        FinancialTransaction $entry,
        string $monthStart,
        string $monthEnd,
    ): array {
        $empty = [
            'period_amount' => null,
            'period_note' => null,
        ];

        if (
            $entry->type !== FinancialTransactionType::Expense
            || ! $entry->relationLoaded('installments')
        ) {
            return $empty;
        }

        $inMonth = $entry->installments
            ->filter(function (TransactionInstallment $installment) use ($monthStart, $monthEnd): bool {
                $competence = $installment->competence_month->toDateString();

                return $installment->status !== TransactionInstallmentStatus::Cancelled
                    && $competence >= $monthStart
                    && $competence <= $monthEnd;
            })
            ->sortBy('installment_number')
            ->values();

        if ($inMonth->isEmpty()) {
            return $empty;
        }

        $hasRefund = $this->hasConfirmedRefund($entry);
        $cents = 0;

        foreach ($inMonth as $installment) {
            $gross = $this->moneyToCents((string) $installment->amount);
            $refund = 0;

            if ($hasRefund) {
                $installment->setRelation('transaction', $entry);
                $refund = $this->refundService
                    ->allocatedRefundCentsForInstallment($installment);
            }

            $cents += max(0, $gross - $refund);
        }

        $net = $hasRefund
            ? $this->refundService->netAmountCents($entry)
            : $this->moneyToCents((string) $entry->amount);
        $note = null;

        if ($cents !== $net) {
            if ($inMonth->count() === 1) {
                $item = $inMonth->first();
                $note = "Parcela {$item->installment_number} de {$item->total_installments}";
            } else {
                $note = $inMonth->count().' parcelas neste mês';
            }
        }

        return [
            'period_amount' => $this->centsToMoney($cents),
            'period_note' => $note,
        ];
    }

    private function hasConfirmedRefund(FinancialTransaction $entry): bool
    {
        if (! $entry->relationLoaded('refunds')) {
            return $entry->refunds()
                ->where('status', ExpenseRefundStatus::Confirmed->value)
                ->where('amount', '>', 0)
                ->exists();
        }

        return $entry->refunds->contains(
            fn ($refund): bool => $refund->status === ExpenseRefundStatus::Confirmed
                && $this->moneyToCents((string) $refund->amount) > 0,
        );
    }

    private function moneyToCents(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');

        if ($negative) {
            $amount = substr($amount, 1);
        }

        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $decimal;

        return $negative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);

        return ($negative ? '-' : '').sprintf(
            '%d.%02d',
            intdiv($absolute, 100),
            $absolute % 100,
        );
    }

    private function periodStart(?string $period): CarbonImmutable
    {
        $today = CarbonImmutable::today();

        if ($period === null || $period === '') {
            return $today->startOfMonth();
        }

        if (preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', $period, $matches) !== 1) {
            return $today->startOfMonth();
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];

        if ($year < 1990 || $year > 2100 || $month < 1 || $month > 12) {
            return $today->startOfMonth();
        }

        return CarbonImmutable::create($year, $month, 1)->startOfMonth();
    }
}
