<?php

namespace App\Http\Controllers;

use App\Enums\AccountMovementType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\TransactionInstallmentStatus;
use App\Models\Category;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function __invoke(): Response
    {
        $workspace = $this->workspace();
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();

        $openingBalance = $this->sumMoney(
            $workspace->financialAccounts()->pluck('opening_balance')->all(),
        );
        $confirmedMovements = $this->sumMoney(
            $workspace->accountMovements()
                ->where(function ($query): void {
                    $query
                        ->whereHas(
                            'transaction',
                            fn ($transactionQuery) => $transactionQuery->where(
                                'status',
                                FinancialTransactionStatus::Confirmed->value,
                            ),
                        )
                        ->orWhereNotNull('credit_card_invoice_payment_id');
                })
                ->pluck('amount')
                ->all(),
        );
        $currentBalance = $openingBalance + $confirmedMovements;

        $monthlyTotals = $this->managerialTotals(
            $workspace,
            $monthStart,
            $monthEnd,
        );
        $outstandingTotals = $this->outstandingTotals($workspace);
        $cardOutstanding = $this->cardInvoiceOutstanding($workspace);

        $projectedBalance = $currentBalance
            + $outstandingTotals[FinancialTransactionType::Income->value]
            - $outstandingTotals[FinancialTransactionType::Expense->value]
            - $cardOutstanding;

        return Inertia::render('dashboard', [
            'currentPeriod' => $monthStart->toDateString(),
            'metrics' => [
                'current_balance' => $this->money($currentBalance),
                'income' => $this->money(
                    $monthlyTotals[FinancialTransactionType::Income->value],
                ),
                'expenses' => $this->money(
                    $monthlyTotals[FinancialTransactionType::Expense->value],
                ),
                'projected_balance' => $this->money($projectedBalance),
                'active_accounts' => $workspace->financialAccounts()
                    ->where('is_active', true)
                    ->count(),
            ],
            'cashFlow' => $this->cashFlow($workspace, $today),
            'categoryExpenses' => $this->categoryExpenses(
                $workspace,
                $monthStart,
                $monthEnd,
            ),
            'upcomingEntries' => $this->upcomingEntries($workspace, $today),
            'recentEntries' => $this->recentEntries($workspace),
        ]);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    /**
     * @return array{income: int, expense: int}
     */
    private function managerialTotals(
        Workspace $workspace,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $totals = [
            FinancialTransactionType::Income->value => 0,
            FinancialTransactionType::Expense->value => 0,
        ];

        $workspace->financialTransactions()
            ->where('status', FinancialTransactionStatus::Confirmed->value)
            ->whereNull('credit_card_id')
            ->whereIn('type', [
                FinancialTransactionType::Income->value,
                FinancialTransactionType::Expense->value,
            ])
            ->whereBetween('competence_date', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->get(['type', 'amount'])
            ->each(function (FinancialTransaction $entry) use (&$totals): void {
                $totals[$entry->type->value] += $this->moneyToCents(
                    (string) $entry->amount,
                );
            });

        $cardExpenses = TransactionInstallment::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
            ->whereBetween('competence_month', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->whereHas('transaction', fn ($query) => $query
                ->where('status', FinancialTransactionStatus::Confirmed->value)
                ->where('type', FinancialTransactionType::Expense->value))
            ->pluck('amount')
            ->all();

        $totals[FinancialTransactionType::Expense->value] += $this->sumMoney(
            $cardExpenses,
        );

        return $totals;
    }

    /**
     * @return array{income: int, expense: int}
     */
    private function outstandingTotals(Workspace $workspace): array
    {
        $totals = [
            FinancialTransactionType::Income->value => 0,
            FinancialTransactionType::Expense->value => 0,
        ];

        $workspace->financialTransactions()
            ->whereIn('status', [
                FinancialTransactionStatus::Planned->value,
                FinancialTransactionStatus::Confirmed->value,
            ])
            ->whereNull('credit_card_id')
            ->whereNull('settled_on')
            ->whereIn('type', [
                FinancialTransactionType::Income->value,
                FinancialTransactionType::Expense->value,
            ])
            ->get(['type', 'amount'])
            ->each(function (FinancialTransaction $entry) use (&$totals): void {
                $totals[$entry->type->value] += $this->moneyToCents(
                    (string) $entry->amount,
                );
            });

        return $totals;
    }

    private function cardInvoiceOutstanding(Workspace $workspace): int
    {
        $total = 0;

        $workspace->creditCardInvoices()
            ->where('status', '!=', CreditCardInvoiceStatus::Paid->value)
            ->get(['calculated_amount', 'statement_amount', 'paid_amount'])
            ->each(function ($invoice) use (&$total): void {
                $target = (string) ($invoice->statement_amount
                    ?? $invoice->calculated_amount);
                $outstanding = $this->moneyToCents($target)
                    - $this->moneyToCents((string) $invoice->paid_amount);

                $total += max(0, $outstanding);
            });

        return $total;
    }

    /**
     * @return array<int, array{month: string, income: string, expenses: string, net: string}>
     */
    private function cashFlow(Workspace $workspace, CarbonImmutable $today): array
    {
        $firstMonth = $today->startOfMonth()->subMonths(5);
        $lastMonth = $today->endOfMonth();
        $months = collect();

        for ($index = 0; $index < 6; $index++) {
            $month = $firstMonth->addMonths($index);
            $months->put($month->format('Y-m'), [
                'month' => $month->toDateString(),
                'income' => 0,
                'expenses' => 0,
            ]);
        }

        $workspace->accountMovements()
            ->whereIn('type', [
                AccountMovementType::IncomeReceipt->value,
                AccountMovementType::ExpensePayment->value,
                AccountMovementType::CardPayment->value,
            ])
            ->whereBetween('occurred_on', [
                $firstMonth->toDateString(),
                $lastMonth->toDateString(),
            ])
            ->get(['type', 'occurred_on', 'amount'])
            ->each(function ($movement) use ($months): void {
                $key = $movement->occurred_on->format('Y-m');
                $month = $months->get($key);

                if ($month === null) {
                    return;
                }

                $amount = abs($this->moneyToCents((string) $movement->amount));

                if ($movement->type === AccountMovementType::IncomeReceipt) {
                    $month['income'] += $amount;
                } else {
                    $month['expenses'] += $amount;
                }

                $months->put($key, $month);
            });

        return $months
            ->values()
            ->map(fn (array $month): array => [
                'month' => $month['month'],
                'income' => $this->money($month['income']),
                'expenses' => $this->money($month['expenses']),
                'net' => $this->money($month['income'] - $month['expenses']),
            ])
            ->all();
    }

    /**
     * @return array<int, array{name: string, amount: string, percentage: float}>
     */
    private function categoryExpenses(
        Workspace $workspace,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): array {
        /** @var array<string, int> $totals */
        $totals = [];

        $workspace->financialTransactions()
            ->where('type', FinancialTransactionType::Expense->value)
            ->where('status', FinancialTransactionStatus::Confirmed->value)
            ->whereNull('credit_card_id')
            ->whereBetween('competence_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->with(['category:id,name,parent_id', 'category.parent:id,name'])
            ->get(['id', 'category_id', 'amount'])
            ->each(function (FinancialTransaction $entry) use (&$totals): void {
                $name = $this->categoryName($entry->category);
                $totals[$name] = ($totals[$name] ?? 0)
                    + $this->moneyToCents((string) $entry->amount);
            });

        TransactionInstallment::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
            ->whereBetween('competence_month', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->whereHas('transaction', fn ($query) => $query
                ->where('status', FinancialTransactionStatus::Confirmed->value)
                ->where('type', FinancialTransactionType::Expense->value))
            ->with([
                'transaction:id,category_id',
                'transaction.category:id,name,parent_id',
                'transaction.category.parent:id,name',
            ])
            ->get(['id', 'financial_transaction_id', 'amount'])
            ->each(function (TransactionInstallment $installment) use (&$totals): void {
                $name = $this->categoryName($installment->transaction?->category);
                $totals[$name] = ($totals[$name] ?? 0)
                    + $this->moneyToCents((string) $installment->amount);
            });

        arsort($totals);
        $grandTotal = array_sum($totals);
        $items = [];

        foreach (array_slice($totals, 0, 5, true) as $name => $amount) {
            $items[] = [
                'name' => $name,
                'amount' => $this->money($amount),
                'percentage' => $grandTotal > 0
                    ? round(($amount / $grandTotal) * 100, 1)
                    : 0,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function upcomingEntries(
        Workspace $workspace,
        CarbonImmutable $today,
    ): array {
        return $workspace->financialTransactions()
            ->whereIn('status', [
                FinancialTransactionStatus::Planned->value,
                FinancialTransactionStatus::Confirmed->value,
            ])
            ->whereNull('credit_card_id')
            ->whereNull('settled_on')
            ->whereIn('type', [
                FinancialTransactionType::Income->value,
                FinancialTransactionType::Expense->value,
            ])
            ->whereBetween('due_date', [
                $today->toDateString(),
                $today->addDays(30)->toDateString(),
            ])
            ->with(['category:id,name,parent_id', 'category.parent:id,name'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (FinancialTransaction $entry): array => [
                'id' => $entry->id,
                'type' => $entry->type->value,
                'description' => $entry->description,
                'amount' => $entry->amount,
                'date' => $entry->due_date->toDateString(),
                'status' => $entry->status->value,
                'status_label' => $entry->status->label(),
                'category' => $this->categoryName($entry->category),
                'context' => collect([
                    $entry->payment_method?->label(),
                    $entry->payee_name,
                ])->filter()->join(' · ') ?: null,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentEntries(Workspace $workspace): array
    {
        return $workspace->financialTransactions()
            ->where('status', '!=', FinancialTransactionStatus::Cancelled->value)
            ->with([
                'account:id,name',
                'creditCard:id,name,last_four',
                'category:id,name,parent_id',
                'category.parent:id,name',
                'sourceAccount:id,name',
                'destinationAccount:id,name',
            ])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (FinancialTransaction $entry): array => [
                'id' => $entry->id,
                'type' => $entry->type->value,
                'description' => $entry->description,
                'amount' => $entry->amount,
                'date' => $entry->transaction_date->toDateString(),
                'status' => $entry->status->value,
                'status_label' => $entry->status->label(),
                'category' => $entry->type === FinancialTransactionType::Transfer
                    ? 'Transferência'
                    : $this->categoryName($entry->category),
                'context' => $this->entryContext($entry),
            ])
            ->all();
    }

    private function entryContext(FinancialTransaction $entry): ?string
    {
        if ($entry->type === FinancialTransactionType::Transfer) {
            return collect([
                $entry->sourceAccount?->name,
                $entry->destinationAccount?->name,
            ])->filter()->join(' → ') ?: null;
        }

        if ($entry->creditCard !== null) {
            return "{$entry->creditCard->name} · final {$entry->creditCard->last_four}";
        }

        return $entry->account?->name;
    }

    private function categoryName(?Category $category): string
    {
        return $category?->parent?->name
            ?? $category?->name
            ?? 'Sem categoria';
    }

    /**
     * @param  array<int, mixed>  $amounts
     */
    private function sumMoney(array $amounts): int
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $this->moneyToCents((string) $amount);
        }

        return $total;
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

    private function money(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $formatted = sprintf(
            '%d.%02d',
            intdiv($absolute, 100),
            $absolute % 100,
        );

        return $negative ? '-'.$formatted : $formatted;
    }
}
