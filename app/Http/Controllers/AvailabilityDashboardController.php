<?php

namespace App\Http\Controllers;

use App\Enums\AccountMovementType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\Workspace;
use App\Services\Finance\FinancialRecurrenceService;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class AvailabilityDashboardController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly FinancialRecurrenceService $recurrenceService,
    ) {}

    public function __invoke(): Response
    {
        $workspace = $this->workspace();
        $today = CarbonImmutable::today();

        $this->recurrenceService->generateForWorkspace($workspace);

        $accounts = $this->accountsWithCurrentBalance($workspace)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (FinancialAccount $account): array => $this->accountData($account))
            ->values();

        $usedLimits = $this->usedLimitsByCard($workspace);
        $cards = $workspace
            ->creditCards()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CreditCard $card): array => $this->cardData(
                $card,
                (string) ($usedLimits[$card->id] ?? '0.00'),
                $today,
            ))
            ->sortBy(fn (array $card): string => $card['next_closing_date'].'-'.$card['name'])
            ->values();

        $nextMonthStart = $today->addMonthNoOverflow()->startOfMonth();
        $nextMonthEnd = $nextMonthStart->endOfMonth();
        $receivables = $this->nextMonthReceivables($workspace, $nextMonthStart, $nextMonthEnd);

        $accountBalanceCents = $accounts->sum(
            fn (array $account): int => $this->moneyToCents($account['current_balance']),
        );
        $overdraftLimitCents = $accounts->sum(
            fn (array $account): int => $this->moneyToCents($account['overdraft_limit']),
        );
        $overdraftUsedCents = $accounts->sum(
            fn (array $account): int => $this->moneyToCents($account['overdraft_used']),
        );
        $overdraftAvailableCents = $accounts->sum(
            fn (array $account): int => $this->moneyToCents($account['overdraft_available']),
        );
        $cardLimitCents = $cards->sum(
            fn (array $card): int => $this->moneyToCents($card['credit_limit']),
        );
        $cardUsedCents = $cards->sum(
            fn (array $card): int => $this->moneyToCents($card['used_limit']),
        );
        $cardAvailableCents = $cards->sum(
            fn (array $card): int => $this->moneyToCents($card['available_limit']),
        );
        $nextMonthReceivableCents = collect($receivables)->sum(
            fn (array $receivable): int => $this->moneyToCents($receivable['amount']),
        );

        return Inertia::render('availability-dashboard', [
            'asOf' => $today->toDateString(),
            'nextMonth' => $nextMonthStart->toDateString(),
            'summary' => [
                'account_balance' => $this->centsToMoney($accountBalanceCents),
                'overdraft_limit' => $this->centsToMoney($overdraftLimitCents),
                'overdraft_used' => $this->centsToMoney($overdraftUsedCents),
                'overdraft_available' => $this->centsToMoney($overdraftAvailableCents),
                'card_limit' => $this->centsToMoney($cardLimitCents),
                'card_used' => $this->centsToMoney($cardUsedCents),
                'card_available' => $this->centsToMoney($cardAvailableCents),
                'next_month_receivable' => $this->centsToMoney($nextMonthReceivableCents),
            ],
            'accounts' => $accounts,
            'cards' => $cards,
            'receivables' => $receivables,
            'nextClosingCard' => $cards->first(),
        ]);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
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
                                OR account_movements.expense_refund_id IS NOT NULL
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
     * @return array<string, mixed>
     */
    private function accountData(FinancialAccount $account): array
    {
        $currentBalance = (string) ($account->getAttribute('current_balance')
            ?? $account->opening_balance);
        $currentBalanceCents = $this->moneyToCents($currentBalance);
        $overdraftLimitCents = $this->moneyToCents((string) $account->overdraft_limit);
        $overdraftUsedCents = min($overdraftLimitCents, max(0, -$currentBalanceCents));
        $overdraftAvailableCents = max(0, $overdraftLimitCents - $overdraftUsedCents);

        return [
            'id' => $account->id,
            'name' => $account->name,
            'institution' => $account->institution,
            'type_label' => $account->type->label(),
            'current_balance' => $currentBalance,
            'overdraft_limit' => $this->centsToMoney($overdraftLimitCents),
            'overdraft_used' => $this->centsToMoney($overdraftUsedCents),
            'overdraft_available' => $this->centsToMoney($overdraftAvailableCents),
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
    private function cardData(
        CreditCard $card,
        string $usedLimit,
        CarbonImmutable $today,
    ): array {
        $limitCents = $this->moneyToCents((string) $card->credit_limit);
        $usedCents = $this->moneyToCents($usedLimit);
        $nextClosingDate = $this->nextClosingDate($card->closing_day, $today);

        return [
            'id' => $card->id,
            'name' => $card->name,
            'institution' => $card->institution,
            'last_four' => $card->last_four,
            'credit_limit' => $this->centsToMoney($limitCents),
            'used_limit' => $this->centsToMoney($usedCents),
            'available_limit' => $this->centsToMoney(max(0, $limitCents - $usedCents)),
            'closing_day' => $card->closing_day,
            'due_day' => $card->due_day,
            'next_closing_date' => $nextClosingDate->toDateString(),
            'days_until_closing' => (int) $today->startOfDay()->diffInDays($nextClosingDate->startOfDay()),
        ];
    }

    private function nextClosingDate(int $closingDay, CarbonImmutable $today): CarbonImmutable
    {
        $monthStart = $today->startOfMonth();
        $closingDate = $monthStart->day(min($closingDay, $monthStart->daysInMonth));

        if ($closingDate->lt($today->startOfDay())) {
            $monthStart = $today->addMonthNoOverflow()->startOfMonth();
            $closingDate = $monthStart->day(min($closingDay, $monthStart->daysInMonth));
        }

        return $closingDate;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nextMonthReceivables(
        Workspace $workspace,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        return $workspace
            ->financialTransactions()
            ->where('type', FinancialTransactionType::Income->value)
            ->whereIn('status', [
                FinancialTransactionStatus::Planned->value,
                FinancialTransactionStatus::Confirmed->value,
            ])
            ->whereNull('settled_on')
            ->whereNull('credit_card_id')
            ->whereDoesntHave('installments')
            ->where(function ($query) use ($start, $end): void {
                $query
                    ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function ($fallback) use ($start, $end): void {
                        $fallback
                            ->whereNull('due_date')
                            ->whereBetween('transaction_date', [
                                $start->toDateString(),
                                $end->toDateString(),
                            ]);
                    });
            })
            ->with('account:id,name')
            ->orderByRaw('COALESCE(due_date, transaction_date)')
            ->orderBy('id')
            ->get()
            ->map(function (FinancialTransaction $transaction): array {
                $date = $transaction->due_date ?? $transaction->transaction_date;

                return [
                    'id' => $transaction->id,
                    'description' => $transaction->description,
                    'amount' => (string) $transaction->amount,
                    'expected_on' => $date->toDateString(),
                    'account_name' => $transaction->account?->name,
                ];
            })
            ->all();
    }

    private function moneyToCents(string $amount): int
    {
        $amount = trim($amount);
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
