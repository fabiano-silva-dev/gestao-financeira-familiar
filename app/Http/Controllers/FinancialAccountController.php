<?php

namespace App\Http\Controllers;

use App\Enums\AccountMovementType;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialTransactionStatus;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Http\Requests\UpdateFinancialAccountRequest;
use App\Models\AccountMovement;
use App\Models\FinancialAccount;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FinancialAccountController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function index(): Response
    {
        $workspace = $this->workspace();

        $accounts = $this->accountsWithCurrentBalance($workspace)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (FinancialAccount $account): array => $this->accountData($account));

        $activeAccounts = $accounts->where('is_active', true);
        $totalBalanceCents = $activeAccounts->sum(
            fn (array $account): int => $this->moneyToCents($account['current_balance']),
        );

        return Inertia::render('accounts/index', [
            'accounts' => $accounts,
            'summary' => [
                'total_balance' => $this->centsToMoney($totalBalanceCents),
                'active_accounts' => $activeAccounts->count(),
            ],
        ]);
    }

    public function show(int $account): Response
    {
        $workspace = $this->workspace();
        $financialAccount = $this->accountsWithCurrentBalance($workspace)
            ->findOrFail($account);

        $movementQuery = $this->effectiveMovementsQuery($financialAccount);

        $stats = (clone $movementQuery)
            ->selectRaw(
                <<<'SQL'
                    COUNT(*) AS movement_count,
                    COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS inflows,
                    COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS outflows
                SQL,
            )
            ->first();

        $movements = (clone $movementQuery)
            ->with([
                'transaction.category.parent',
                'transaction.familyMember',
                'transaction.sourceAccount:id,name',
                'transaction.destinationAccount:id,name',
                'invoicePayment.invoice.creditCard:id,name',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (AccountMovement $movement): array => $this->movementData($movement));

        return Inertia::render('accounts/show', [
            'account' => $this->accountData($financialAccount),
            'summary' => [
                'inflows' => (string) ($stats?->getAttribute('inflows') ?? '0.00'),
                'outflows' => (string) ($stats?->getAttribute('outflows') ?? '0.00'),
                'movement_count' => (int) ($stats?->getAttribute('movement_count') ?? 0),
            ],
            'movements' => $movements,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('accounts/create', [
            'accountTypes' => FinancialAccountType::options(),
        ]);
    }

    public function store(StoreFinancialAccountRequest $request): RedirectResponse
    {
        $this->workspace()->financialAccounts()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Conta cadastrada com sucesso.',
        ]);

        return to_route('accounts.index');
    }

    public function edit(int $account): Response
    {
        return Inertia::render('accounts/edit', [
            'account' => $this->accountData($this->findAccount($account)),
            'accountTypes' => FinancialAccountType::options(),
        ]);
    }

    public function update(
        UpdateFinancialAccountRequest $request,
        int $account,
    ): RedirectResponse {
        $this->findAccount($account)->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Conta atualizada com sucesso.',
        ]);

        return to_route('accounts.index');
    }

    public function toggleStatus(int $account): RedirectResponse
    {
        $financialAccount = $this->findAccount($account);
        $financialAccount->update([
            'is_active' => ! $financialAccount->is_active,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $financialAccount->is_active
                ? 'Conta ativada com sucesso.'
                : 'Conta desativada com sucesso.',
        ]);

        return to_route('accounts.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findAccount(int $account): FinancialAccount
    {
        return $this->workspace()
            ->financialAccounts()
            ->findOrFail($account);
    }

    /**
     * @return Builder<FinancialAccount>
     */
    private function accountsWithCurrentBalance(Workspace $workspace): Builder
    {
        return $workspace
            ->financialAccounts()
            ->getQuery()
            ->select('financial_accounts.*')
            ->selectRaw(
                <<<'SQL'
                    financial_accounts.opening_balance + COALESCE((
                        SELECT SUM(account_movements.amount)
                        FROM account_movements
                        LEFT JOIN financial_transactions
                            ON financial_transactions.id = account_movements.financial_transaction_id
                        WHERE account_movements.financial_account_id = financial_accounts.id
                            AND account_movements.workspace_id = financial_accounts.workspace_id
                            AND (
                                financial_accounts.opening_balance_date IS NULL
                                OR account_movements.occurred_on > financial_accounts.opening_balance_date
                            )
                            AND (
                                financial_transactions.status = ?
                                OR account_movements.credit_card_invoice_payment_id IS NOT NULL
                                OR account_movements.type = ?
                            )
                    ), 0) AS current_balance
                SQL,
                [
                    FinancialTransactionStatus::Confirmed->value,
                    AccountMovementType::Adjustment->value,
                ],
            );
    }

    /**
     * @return Builder<AccountMovement>
     */
    private function effectiveMovementsQuery(FinancialAccount $account): Builder
    {
        $query = $account
            ->movements()
            ->getQuery()
            ->where(function ($query): void {
                $query
                    ->whereHas(
                        'transaction',
                        fn ($transactionQuery) => $transactionQuery->where(
                            'status',
                            FinancialTransactionStatus::Confirmed->value,
                        ),
                    )
                    ->orWhereNotNull('credit_card_invoice_payment_id')
                    ->orWhere('type', AccountMovementType::Adjustment->value);
            });

        if ($account->opening_balance_date !== null) {
            $query->where('occurred_on', '>', $account->opening_balance_date->toDateString());
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function movementData(AccountMovement $movement): array
    {
        $transaction = $movement->transaction;
        $categoryName = $transaction?->category?->name;

        if ($transaction?->category?->parent !== null) {
            $categoryName = "{$transaction->category->parent->name} / {$transaction->category->name}";
        }

        $counterpartyAccountName = match ($movement->type) {
            AccountMovementType::TransferOut => $transaction?->destinationAccount?->name,
            AccountMovementType::TransferIn => $transaction?->sourceAccount?->name,
            default => null,
        };

        $creditCardName = $movement->invoicePayment?->invoice?->creditCard?->name;

        return [
            'id' => $movement->id,
            'occurred_on' => $movement->occurred_on->toDateString(),
            'description' => $movement->description,
            'amount' => $movement->amount,
            'type' => $movement->type->value,
            'type_label' => $movement->type->label(),
            'is_reconciled' => $movement->is_reconciled,
            'transaction_id' => $transaction?->id,
            'transaction_type' => $transaction?->type->value,
            'transaction_type_label' => $transaction?->type->label(),
            'category_name' => $categoryName,
            'family_member_name' => $transaction?->familyMember?->name,
            'counterparty_account_name' => $counterpartyAccountName,
            'credit_card_name' => $creditCardName,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     institution: string|null,
     *     type: string,
     *     type_label: string,
     *     opening_balance: string,
     *     opening_balance_date: string|null,
     *     current_balance: string,
     *     is_active: bool
     * }
     */
    private function accountData(FinancialAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'institution' => $account->institution,
            'type' => $account->type->value,
            'type_label' => $account->type->label(),
            'opening_balance' => $account->opening_balance,
            'opening_balance_date' => $account->opening_balance_date?->toDateString(),
            'current_balance' => (string) ($account->getAttribute('current_balance')
                ?? $account->opening_balance),
            'is_active' => $account->is_active,
        ];
    }

    private function moneyToCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $unsigned = ltrim($amount, '+-');
        [$whole, $decimal] = array_pad(explode('.', $unsigned, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $decimal;

        return $negative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $amount = sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);

        return $negative ? '-'.$amount : $amount;
    }
}
