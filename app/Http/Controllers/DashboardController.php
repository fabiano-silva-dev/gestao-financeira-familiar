<?php

namespace App\Http\Controllers;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Models\Category;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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

        $openingBalance = (float) $workspace->financialAccounts()->sum('opening_balance');
        $confirmedMovements = (float) $workspace->accountMovements()
            ->whereHas('transaction', fn ($query) => $query->where(
                'status',
                FinancialTransactionStatus::Confirmed->value,
            ))
            ->sum('amount');
        $currentBalance = $openingBalance + $confirmedMovements;

        $monthlyTotals = $this->totalsByType(
            $workspace,
            FinancialTransactionStatus::Confirmed,
            $monthStart,
            $monthEnd,
        );
        $plannedTotals = $this->totalsByType(
            $workspace,
            FinancialTransactionStatus::Planned,
        );
        $income = $this->totalFor($monthlyTotals, FinancialTransactionType::Income);
        $expenses = $this->totalFor($monthlyTotals, FinancialTransactionType::Expense);
        $projectedBalance = $currentBalance
            + $this->totalFor($plannedTotals, FinancialTransactionType::Income)
            - $this->totalFor($plannedTotals, FinancialTransactionType::Expense);

        return Inertia::render('dashboard', [
            'currentPeriod' => $monthStart->toDateString(),
            'metrics' => [
                'current_balance' => $this->money($currentBalance),
                'income' => $this->money($income),
                'expenses' => $this->money($expenses),
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
     * @return Collection<string, string>
     */
    private function totalsByType(
        Workspace $workspace,
        FinancialTransactionStatus $status,
        ?CarbonImmutable $start = null,
        ?CarbonImmutable $end = null,
    ): Collection {
        $query = $workspace->financialTransactions()
            ->where('status', $status->value)
            ->whereIn('type', [
                FinancialTransactionType::Income->value,
                FinancialTransactionType::Expense->value,
            ]);

        if ($start !== null && $end !== null) {
            $query->whereBetween('transaction_date', [
                $start->toDateString(),
                $end->toDateString(),
            ]);
        }

        return $query
            ->selectRaw('type, SUM(amount) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');
    }

    /**
     * @param  Collection<string, string>  $totals
     */
    private function totalFor(
        Collection $totals,
        FinancialTransactionType $type,
    ): float {
        return (float) ($totals->get($type->value) ?? 0);
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
                'income' => 0.0,
                'expenses' => 0.0,
            ]);
        }

        $workspace->financialTransactions()
            ->where('status', FinancialTransactionStatus::Confirmed->value)
            ->whereIn('type', [
                FinancialTransactionType::Income->value,
                FinancialTransactionType::Expense->value,
            ])
            ->whereBetween('transaction_date', [
                $firstMonth->toDateString(),
                $lastMonth->toDateString(),
            ])
            ->get(['type', 'transaction_date', 'amount'])
            ->each(function (FinancialTransaction $entry) use ($months): void {
                $key = $entry->transaction_date->format('Y-m');
                $month = $months->get($key);

                if ($month === null) {
                    return;
                }

                $field = $entry->type === FinancialTransactionType::Income
                    ? 'income'
                    : 'expenses';
                $month[$field] += (float) $entry->amount;
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
        $totals = $workspace->financialTransactions()
            ->where('type', FinancialTransactionType::Expense->value)
            ->where('status', FinancialTransactionStatus::Confirmed->value)
            ->whereBetween('transaction_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->with(['category:id,name,parent_id', 'category.parent:id,name'])
            ->get(['id', 'category_id', 'amount'])
            ->groupBy(fn (FinancialTransaction $entry): string => $this->categoryName($entry->category))
            ->map(fn (Collection $entries): float => (float) $entries->sum('amount'))
            ->sortDesc();
        $total = (float) $totals->sum();

        return $totals
            ->take(5)
            ->map(fn (float $amount, string $name): array => [
                'name' => $name,
                'amount' => $this->money($amount),
                'percentage' => $total > 0
                    ? round(($amount / $total) * 100, 1)
                    : 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function upcomingEntries(
        Workspace $workspace,
        CarbonImmutable $today,
    ): array {
        return $workspace->financialTransactions()
            ->where('status', FinancialTransactionStatus::Planned->value)
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

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
