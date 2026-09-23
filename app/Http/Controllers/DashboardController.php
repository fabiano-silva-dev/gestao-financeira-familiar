<?php

namespace App\Http\Controllers;

use App\Enums\AccountMovementType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\TransactionInstallmentStatus;
use App\Models\Category;
use App\Models\CreditCardInvoice;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\Workspace;
use App\Services\Finance\CreditCardInvoiceService;
use App\Services\Finance\ExpenseRefundService;
use App\Services\Finance\FinancialRecurrenceService;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly FinancialRecurrenceService $recurrenceService,
        private readonly ExpenseRefundService $refundService,
        private readonly CreditCardInvoiceService $invoiceService,
    ) {}

    public function __invoke(Request $request): Response
    {
        $workspace = $this->workspace();
        $today = CarbonImmutable::today();
        $this->recurrenceService->generateForWorkspace($workspace);
        $monthStart = $this->periodStart($request, $today);
        $monthEnd = $monthStart->endOfMonth();

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
                        ->orWhereNotNull('credit_card_invoice_payment_id')
                        ->orWhereNotNull('expense_refund_id');
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
            'cashFlow' => $this->cashFlow($workspace, $monthStart),
            'categoryIncomes' => $this->categoryIncomes(
                $workspace,
                $monthStart,
                $monthEnd,
            ),
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
            ->whereNotNull('settled_on')
            ->whereIn('type', [
                FinancialTransactionType::Income->value,
                FinancialTransactionType::Expense->value,
            ])
            ->whereBetween('competence_date', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->with('refunds:id,financial_transaction_id,amount,status')
            ->get(['id', 'type', 'amount'])
            ->each(function (FinancialTransaction $entry) use (&$totals): void {
                if ($entry->type === FinancialTransactionType::Income) {
                    $totals['income'] += $this->moneyToCents((string) $entry->amount);
                }

                if ($entry->type === FinancialTransactionType::Expense) {
                    $totals['expense'] += $this->refundService->netAmountCents($entry);
                }
            });

        $totals['expense'] += TransactionInstallment::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
            ->whereBetween('competence_month', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->whereHas('transaction', fn ($query) => $query
                ->where('status', FinancialTransactionStatus::Confirmed->value)
                ->where('type', FinancialTransactionType::Expense->value))
            ->with('transaction:id,amount')
            ->get(['id', 'financial_transaction_id', 'amount', 'installment_number'])
            ->sum(function (TransactionInstallment $installment): int {
                $gross = $this->moneyToCents((string) $installment->amount);
                $refund = $this->refundService
                    ->allocatedRefundCentsForInstallment($installment);

                return max(0, $gross - $refund);
            });

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
            ->with('refunds:id,financial_transaction_id,amount,status')
            ->get(['id', 'type', 'amount'])
            ->each(function (FinancialTransaction $entry) use (&$totals): void {
                if ($entry->type === FinancialTransactionType::Income) {
                    $totals['income'] += $this->moneyToCents((string) $entry->amount);
                }

                if ($entry->type === FinancialTransactionType::Expense) {
                    $totals['expense'] += $this->refundService->netAmountCents($entry);
                }
            });

        return $totals;
    }

    private function cardInvoiceOutstanding(Workspace $workspace): int
    {
        $total = 0;

        $workspace->creditCardInvoices()
            ->where('status', '!=', CreditCardInvoiceStatus::Paid->value)
            ->get()
            ->each(function (CreditCardInvoice $invoice) use (&$total): void {
                $total += $this->invoiceService->outstandingCents($invoice);
            });

        return $total;
    }

    private function periodStart(Request $request, CarbonImmutable $today): CarbonImmutable
    {
        $period = $request->query('period');

        if (! is_string($period) || $period === '') {
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

    /**
     * @return array<int, array{month: string, income: string, expenses: string, net: string}>
     */
    private function cashFlow(Workspace $workspace, CarbonImmutable $referenceMonth): array
    {
        $firstMonth = $referenceMonth->startOfMonth()->subMonths(5);
        $lastMonth = $referenceMonth->endOfMonth();
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
                AccountMovementType::Refund->value,
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
                } elseif ($movement->type === AccountMovementType::Refund) {
                    $month['expenses'] -= $amount;
                } else {
                    $month['expenses'] += $amount;
                }

                $months->put($key, $month);
            });

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
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [
                $firstMonth->toDateString(),
                $lastMonth->toDateString(),
            ])
            ->get(['type', 'due_date', 'amount'])
            ->each(function (FinancialTransaction $entry) use ($months): void {
                $key = $entry->due_date->format('Y-m');
                $month = $months->get($key);

                if ($month === null) {
                    return;
                }

                $amount = abs($this->moneyToCents((string) $entry->amount));

                if ($entry->type === FinancialTransactionType::Income) {
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
     * @return array<int, array{id: int|null, name: string, amount: string, percentage: float}>
     */
    private function categoryIncomes(
        Workspace $workspace,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): array {
        /** @var array<string, array{id: int|null, name: string, amount: int}> $totals */
        $totals = [];

        $workspace->financialTransactions()
            ->where('type', FinancialTransactionType::Income->value)
            ->where('status', FinancialTransactionStatus::Confirmed->value)
            ->whereNull('credit_card_id')
            ->whereNotNull('settled_on')
            ->whereBetween('competence_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->with([
                'category:id,name,parent_id',
                'category.parent:id,name',
            ])
            ->get(['id', 'category_id', 'amount'])
            ->each(function (FinancialTransaction $entry) use (&$totals): void {
                $this->addCategoryTotal(
                    $totals,
                    $entry->category,
                    (string) $entry->amount,
                );
            });

        uasort(
            $totals,
            fn (array $left, array $right): int => $right['amount'] <=> $left['amount'],
        );
        $grandTotal = array_sum(array_column($totals, 'amount'));
        $items = [];

        foreach ($totals as $group) {
            $items[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'amount' => $this->money($group['amount']),
                'percentage' => $grandTotal > 0
                    ? round(($group['amount'] / $grandTotal) * 100, 1)
                    : 0,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{id: int|null, name: string, amount: string, percentage: float}>
     */
    private function categoryExpenses(
        Workspace $workspace,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): array {
        /** @var array<string, array{id: int|null, name: string, amount: int}> $totals */
        $totals = [];

        $workspace->financialTransactions()
            ->where('type', FinancialTransactionType::Expense->value)
            ->where('status', FinancialTransactionStatus::Confirmed->value)
            ->whereNull('credit_card_id')
            ->whereNotNull('settled_on')
            ->whereBetween('competence_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->with([
                'category:id,name,parent_id',
                'category.parent:id,name',
                'refunds:id,financial_transaction_id,amount,status',
            ])
            ->get(['id', 'category_id', 'amount'])
            ->each(function (FinancialTransaction $entry) use (&$totals): void {
                $this->addCategoryTotal(
                    $totals,
                    $entry->category,
                    $this->money($this->refundService->netAmountCents($entry)),
                );
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
                'transaction:id,category_id,amount',
                'transaction.refunds:id,financial_transaction_id,amount,status',
                'transaction.category:id,name,parent_id',
                'transaction.category.parent:id,name',
            ])
            ->get(['id', 'financial_transaction_id', 'amount'])
            ->each(function (TransactionInstallment $installment) use (&$totals): void {
                $gross = $this->moneyToCents((string) $installment->amount);
                $refund = $this->refundService
                    ->allocatedRefundCentsForInstallment($installment);
                $this->addCategoryTotal(
                    $totals,
                    $installment->transaction->category,
                    $this->money(max(0, $gross - $refund)),
                );
            });

        uasort(
            $totals,
            fn (array $left, array $right): int => $right['amount'] <=> $left['amount'],
        );
        $grandTotal = array_sum(array_column($totals, 'amount'));
        $items = [];

        foreach ($totals as $group) {
            $items[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'amount' => $this->money($group['amount']),
                'percentage' => $grandTotal > 0
                    ? round(($group['amount'] / $grandTotal) * 100, 1)
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
        $until = $today->addDays(30);

        return collect($this->upcomingTransactions($workspace, $today, $until))
            ->concat($this->upcomingInvoices($workspace, $today, $until))
            ->sortBy([
                ['date', 'asc'],
                ['source', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->take(5)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function upcomingTransactions(
        Workspace $workspace,
        CarbonImmutable $today,
        CarbonImmutable $until,
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
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $until->toDateString())
            ->with(['category:id,name,parent_id', 'category.parent:id,name'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(function (FinancialTransaction $entry) use ($today): array {
                $isOverdue = $entry->due_date->lt($today);

                return [
                    'id' => $entry->id,
                    'source' => 'transaction',
                    'type' => $entry->type->value,
                    'description' => $entry->description,
                    'amount' => $entry->amount,
                    'date' => $entry->due_date->toDateString(),
                    'status' => $isOverdue ? 'overdue' : $entry->status->value,
                    'status_label' => $isOverdue
                        ? 'Vencido'
                        : $entry->status->label(),
                    'is_overdue' => $isOverdue,
                    'category' => $this->categoryName($entry->category),
                    'context' => collect([
                        $entry->payment_method?->label(),
                        $entry->payee_name,
                    ])->filter()->join(' · ') ?: null,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function upcomingInvoices(
        Workspace $workspace,
        CarbonImmutable $today,
        CarbonImmutable $until,
    ): array {
        return $workspace->creditCardInvoices()
            ->where('status', '!=', CreditCardInvoiceStatus::Paid->value)
            ->where('due_date', '<=', $until->toDateString())
            ->with(['creditCard:id,name,last_four'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(function (CreditCardInvoice $invoice) use ($today): ?array {
                $outstanding = $this->invoiceService->outstandingCents($invoice);

                if ($outstanding <= 0) {
                    return null;
                }

                $card = $invoice->creditCard;
                $isOverdue = $invoice->due_date->lt($today);

                return [
                    'id' => $invoice->id,
                    'source' => 'invoice',
                    'type' => FinancialTransactionType::Expense->value,
                    'description' => $card === null
                        ? 'Fatura do cartão'
                        : "Fatura {$card->name}",
                    'amount' => $this->money($outstanding),
                    'date' => $invoice->due_date->toDateString(),
                    'status' => $isOverdue ? 'overdue' : $invoice->status->value,
                    'status_label' => $isOverdue
                        ? 'Vencido'
                        : $invoice->status->label(),
                    'is_overdue' => $isOverdue,
                    'category' => 'Cartão de crédito',
                    'context' => $card === null
                        ? null
                        : "final {$card->last_four}",
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentEntries(Workspace $workspace): array
    {
        return $workspace->financialTransactions()
            ->where('status', '!=', FinancialTransactionStatus::Cancelled->value)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('settled_on')
                    ->orWhereNotNull('credit_card_id')
                    ->orWhere('type', FinancialTransactionType::Transfer->value);
            })
            ->with([
                'account:id,name',
                'creditCard:id,name,last_four',
                'category:id,name,parent_id',
                'category.parent:id,name',
                'sourceAccount:id,name',
                'destinationAccount:id,name',
            ])
            ->orderByRaw('coalesce(settled_on, transaction_date) desc')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (FinancialTransaction $entry): array => [
                'id' => $entry->id,
                'source' => 'transaction',
                'type' => $entry->type->value,
                'description' => $entry->description,
                'amount' => $entry->amount,
                'date' => ($entry->settled_on ?? $entry->transaction_date)->toDateString(),
                'status' => $entry->status->value,
                'status_label' => $this->settlementLabel($entry),
                'is_overdue' => false,
                'category' => $entry->type === FinancialTransactionType::Transfer
                    ? 'Transferência'
                    : $this->categoryName($entry->category),
                'context' => $this->entryContext($entry),
            ])
            ->all();
    }

    private function settlementLabel(FinancialTransaction $entry): string
    {
        if ($entry->type === FinancialTransactionType::Transfer) {
            return $entry->status->label();
        }

        if ($entry->credit_card_id !== null) {
            return $entry->status->label();
        }

        if ($entry->settled_on !== null) {
            return $entry->type === FinancialTransactionType::Expense
                ? 'Pago'
                : 'Recebido';
        }

        return $entry->status->label();
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
        return $this->categoryGroup($category)['name'];
    }

    /**
     * @param  array<string, array{id: int|null, name: string, amount: int}>  $totals
     */
    private function addCategoryTotal(
        array &$totals,
        ?Category $category,
        string $amount,
    ): void {
        $group = $this->categoryGroup($category);
        $current = $totals[$group['key']] ?? [
            'id' => $group['id'],
            'name' => $group['name'],
            'amount' => 0,
        ];
        $current['amount'] += $this->moneyToCents($amount);
        $totals[$group['key']] = $current;
    }

    /**
     * @return array{key: string, id: int|null, name: string}
     */
    private function categoryGroup(?Category $category): array
    {
        if ($category === null) {
            return [
                'key' => 'none',
                'id' => null,
                'name' => 'Sem categoria',
            ];
        }

        if ($category->parent !== null) {
            return [
                'key' => (string) $category->parent->id,
                'id' => $category->parent->id,
                'name' => $category->parent->name,
            ];
        }

        return [
            'key' => (string) $category->id,
            'id' => $category->id,
            'name' => $category->name,
        ];
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
