<?php

namespace App\Http\Controllers;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionInstallmentStatus;
use App\Http\Requests\StoreCreditCardRequest;
use App\Http\Requests\UpdateCreditCardRequest;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\Workspace;
use App\Services\Finance\CreditCardInvoiceService;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditCardController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly CreditCardInvoiceService $invoiceService,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $usedLimits = $this->usedLimitsByCard($workspace);
        $listing = ListingQuery::from(
            $request,
            ['name', 'limit', 'used', 'available', 'status'],
            'status',
            'desc',
            ['status'],
        );
        $query = $workspace
            ->creditCards()
            ->with(['holder:id,name', 'paymentAccount:id,name']);
        $listing->applySearch($query, ['name', 'institution', 'last_four']);

        $active = $listing->booleanFilter('status');

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        if ($listing->sort === 'status') {
            $query->orderBy('is_active', $listing->direction)->orderBy('name');
        } elseif (in_array($listing->sort, ['name'], true)) {
            $listing->applySort($query, ['name' => 'name']);
        }

        $cards = $query
            ->get()
            ->map(fn (CreditCard $card): array => $this->cardData(
                $card,
                (string) ($usedLimits[$card->id] ?? '0.00'),
            ));

        $cards = $listing->sortMapped($cards, [
            'limit' => fn (array $card): int => ListingQuery::moneyToCents($card['credit_limit']),
            'used' => fn (array $card): int => ListingQuery::moneyToCents($card['used_limit']),
            'available' => fn (array $card): int => ListingQuery::moneyToCents($card['available_limit']),
        ]);

        $activeCards = $cards->where('is_active', true);
        $totalLimitCents = $activeCards->sum(
            fn (array $card): int => $this->moneyToCents($card['credit_limit']),
        );
        $usedLimitCents = $activeCards->sum(
            fn (array $card): int => $this->moneyToCents($card['used_limit']),
        );

        return Inertia::render('credit-cards/index', [
            'cards' => $cards,
            'summary' => [
                'total_limit' => $this->centsToMoney($totalLimitCents),
                'used_limit' => $this->centsToMoney($usedLimitCents),
                'available_limit' => $this->centsToMoney(
                    max(0, $totalLimitCents - $usedLimitCents),
                ),
            ],
            'filters' => $listing->toArray(),
            'hasRecords' => $workspace->creditCards()->exists(),
            'statusOptions' => ListingQuery::statusOptions('Ativos', 'Inativos'),
        ]);
    }

    public function show(Request $request, int $card): Response
    {
        $workspace = $this->workspace();
        $creditCard = $this->findCard($card);
        $usedLimits = $this->usedLimitsByCard($workspace);
        $usedLimit = (string) ($usedLimits[$creditCard->id] ?? '0.00');
        $listing = ListingQuery::from(
            $request,
            ['description', 'date', 'status', 'amount'],
            'date',
            'desc',
            ['status'],
        );

        $openInvoices = $creditCard
            ->invoices()
            ->where('status', '!=', CreditCardInvoiceStatus::Paid->value)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $transactionQuery = $creditCard
            ->transactions()
            ->with([
                'category:id,name,parent_id',
                'category.parent:id,name',
                'familyMember:id,name',
                'installments:id,financial_transaction_id,amount,due_date,status',
            ]);
        $hasRecords = (clone $transactionQuery)->exists();
        $listing->applySearch($transactionQuery, ['description']);

        $status = $listing->filter('status');

        if ($status !== null && FinancialTransactionStatus::tryFrom($status) !== null) {
            $transactionQuery->where('status', $status);
        }

        $listing->applySort($transactionQuery, [
            'description' => 'description',
            'date' => 'transaction_date',
            'status' => 'status',
            'amount' => 'amount',
        ]);

        $transactions = $transactionQuery
            ->limit(100)
            ->get()
            ->map(fn (FinancialTransaction $transaction): array => $this->transactionData($transaction));

        $currentInvoice = $openInvoices->get(0);
        $nextInvoice = $openInvoices->get(1);

        return Inertia::render('credit-cards/show', [
            'card' => $this->cardData($creditCard, $usedLimit),
            'currentInvoice' => $currentInvoice === null
                ? null
                : $this->invoiceOverviewData($currentInvoice),
            'nextInvoice' => $nextInvoice === null
                ? null
                : $this->invoiceOverviewData($nextInvoice),
            'transactions' => $transactions,
            'filters' => $listing->toArray(),
            'hasRecords' => $hasRecords,
            'statusOptions' => FinancialTransactionStatus::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('credit-cards/create', [
            ...$this->referenceOptions(),
            'invoicePaymentMethods' => PaymentMethod::invoiceOptions(),
        ]);
    }

    public function store(StoreCreditCardRequest $request): RedirectResponse
    {
        $this->workspace()->creditCards()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Cartão cadastrado com sucesso.',
        ]);

        return to_route('credit-cards.index');
    }

    public function edit(int $card): Response
    {
        return Inertia::render('credit-cards/edit', [
            'card' => $this->cardData($this->findCard($card)),
            ...$this->referenceOptions(),
            'invoicePaymentMethods' => PaymentMethod::invoiceOptions(),
        ]);
    }

    public function update(
        UpdateCreditCardRequest $request,
        int $card,
    ): RedirectResponse {
        $this->findCard($card)->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Cartão atualizado com sucesso.',
        ]);

        return to_route('credit-cards.index');
    }

    public function toggleStatus(int $card): RedirectResponse
    {
        $creditCard = $this->findCard($card);
        $creditCard->update([
            'is_active' => ! $creditCard->is_active,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $creditCard->is_active
                ? 'Cartão ativado com sucesso.'
                : 'Cartão desativado com sucesso.',
        ]);

        return to_route('credit-cards.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findCard(int $card): CreditCard
    {
        return $this->workspace()
            ->creditCards()
            ->with(['holder:id,name', 'paymentAccount:id,name'])
            ->findOrFail($card);
    }

    /**
     * @return array{
     *     memberOptions: array<int, array{id: int, name: string, is_active: bool}>,
     *     accountOptions: array<int, array{id: int, name: string, is_active: bool}>
     * }
     */
    private function referenceOptions(): array
    {
        $workspace = $this->workspace();

        return [
            'memberOptions' => $workspace
                ->familyMembers()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (FamilyMember $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'is_active' => $member->is_active,
                ])
                ->all(),
            'accountOptions' => $workspace
                ->financialAccounts()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (FinancialAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'is_active' => $account->is_active,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function usedLimitsByCard(Workspace $workspace): array
    {
        $openLimits = TransactionInstallment::query()
            ->join(
                'financial_transactions',
                'financial_transactions.id',
                '=',
                'transaction_installments.financial_transaction_id',
            )
            ->where('transaction_installments.workspace_id', $workspace->id)
            ->where('financial_transactions.workspace_id', $workspace->id)
            ->whereNotNull('financial_transactions.credit_card_id')
            ->where(
                'transaction_installments.status',
                TransactionInstallmentStatus::Open->value,
            )
            ->where(
                'financial_transactions.status',
                '!=',
                FinancialTransactionStatus::Cancelled->value,
            )
            ->groupBy('financial_transactions.credit_card_id')
            ->selectRaw(
                'financial_transactions.credit_card_id, SUM(transaction_installments.amount) AS used_limit',
            )
            ->pluck('used_limit', 'financial_transactions.credit_card_id')
            ->all();

        $partialPayments = CreditCardInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', CreditCardInvoiceStatus::Partial->value)
            ->groupBy('credit_card_id')
            ->selectRaw('credit_card_id, SUM(paid_amount) AS paid_amount')
            ->pluck('paid_amount', 'credit_card_id')
            ->all();

        $result = [];

        foreach ($openLimits as $cardId => $amount) {
            $usedCents = $this->moneyToCents((string) $amount);
            $paidCents = $this->moneyToCents(
                (string) ($partialPayments[$cardId] ?? '0.00'),
            );

            $result[(int) $cardId] = $this->centsToMoney(
                max(0, $usedCents - $paidCents),
            );
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceOverviewData(CreditCardInvoice $invoice): array
    {
        $isOverdue = $invoice->status !== CreditCardInvoiceStatus::Paid
            && $invoice->due_date->isBefore(CarbonImmutable::today());

        return [
            'id' => $invoice->id,
            'reference_month' => $invoice->reference_month->toDateString(),
            'closing_date' => $invoice->closing_date->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'total_amount' => $invoice->statement_amount
                ?? $invoice->calculated_amount,
            'outstanding_amount' => $this->invoiceService->outstandingAmount($invoice),
            'status' => $isOverdue ? 'overdue' : $invoice->status->value,
            'status_label' => $isOverdue ? 'Vencida' : $invoice->status->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionData(FinancialTransaction $transaction): array
    {
        $categoryName = $transaction->category?->name;

        if ($transaction->category?->parent !== null) {
            $categoryName = "{$transaction->category->parent->name} / {$transaction->category->name}";
        }

        $openInstallments = $transaction->installments
            ->filter(
                fn (TransactionInstallment $installment): bool => $installment->status
                    === TransactionInstallmentStatus::Open,
            )
            ->sortBy('due_date')
            ->values();
        $nextOpenInstallment = $openInstallments->first();

        return [
            'id' => $transaction->id,
            'transaction_date' => $transaction->transaction_date->toDateString(),
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'status' => $transaction->status->value,
            'status_label' => $transaction->status->label(),
            'category_name' => $categoryName,
            'family_member_name' => $transaction->familyMember?->name,
            'installment_count' => max(1, $transaction->installments->count()),
            'open_installment_count' => $openInstallments->count(),
            'next_due_date' => $nextOpenInstallment === null
                ? null
                : $nextOpenInstallment->due_date->toDateString(),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     institution: string|null,
     *     last_four: string,
     *     holder_id: int|null,
     *     holder_name: string|null,
     *     credit_limit: string,
     *     used_limit: string,
     *     available_limit: string,
     *     closing_day: int,
     *     due_day: int,
     *     payment_account_id: int|null,
     *     payment_account_name: string|null,
     *     invoice_payment_method: string,
     *     invoice_payment_method_label: string,
     *     payment_instructions: string|null,
     *     is_active: bool
     * }
     */
    private function cardData(CreditCard $card, string $usedLimit = '0.00'): array
    {
        $limitCents = $this->moneyToCents($card->credit_limit);
        $usedCents = $this->moneyToCents($usedLimit);

        return [
            'id' => $card->id,
            'name' => $card->name,
            'institution' => $card->institution,
            'last_four' => $card->last_four,
            'holder_id' => $card->holder_id,
            'holder_name' => $card->holder?->name,
            'credit_limit' => $card->credit_limit,
            'used_limit' => $this->centsToMoney($usedCents),
            'available_limit' => $this->centsToMoney(
                max(0, $limitCents - $usedCents),
            ),
            'closing_day' => $card->closing_day,
            'due_day' => $card->due_day,
            'payment_account_id' => $card->payment_account_id,
            'payment_account_name' => $card->paymentAccount?->name,
            'invoice_payment_method' => $card->invoice_payment_method->value,
            'invoice_payment_method_label' => $card->invoice_payment_method->label(),
            'payment_instructions' => $card->payment_instructions,
            'is_active' => $card->is_active,
        ];
    }

    private function moneyToCents(string $amount): int
    {
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');

        return ((int) $whole * 100) + (int) $decimal;
    }

    private function centsToMoney(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
