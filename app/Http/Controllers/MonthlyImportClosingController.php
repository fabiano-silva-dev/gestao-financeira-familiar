<?php

namespace App\Http\Controllers;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\ImportPeriodClosure;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class MonthlyImportClosingController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $period = $this->period($request->query('period'));
        $view = $this->view($request->query('view'));
        $monthStart = $period->startOfMonth();
        $monthEnd = $period->endOfMonth();
        $periodKey = $period->format('Y-m');

        $closures = $workspace->importPeriodClosures()
            ->whereDate('period_month', $monthStart->toDateString())
            ->with(['closedBy:id,name', 'reopenedBy:id,name'])
            ->get();

        $imports = $workspace->financialImports()
            ->where('status', FinancialImportStatus::Completed->value)
            ->where(function ($query) use ($monthStart, $monthEnd, $periodKey): void {
                $query->where(function ($statement) use ($monthStart, $monthEnd): void {
                    $statement
                        ->whereNotNull('financial_account_id')
                        ->whereDate('statement_start_on', '<=', $monthEnd->toDateString())
                        ->whereDate('statement_end_on', '>=', $monthStart->toDateString());
                })->orWhere(function ($invoice) use ($periodKey): void {
                    $invoice
                        ->whereNotNull('credit_card_id')
                        ->where('metadata->reference_month', $periodKey);
                });
            })
            ->orderBy('imported_at')
            ->get();

        $bankEntries = $workspace->bankStatementEntries()
            ->whereBetween('occurred_on', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get(['id', 'financial_account_id', 'financial_import_id', 'is_reconciled', 'is_ignored']);
        $cardEntries = $workspace->cardStatementEntries()
            ->with('invoice:id,reference_month,due_date,statement_amount,calculated_amount')
            ->whereHas('invoice', fn ($query) => $query->whereDate(
                'reference_month',
                $monthStart->toDateString(),
            ))
            ->get([
                'id',
                'credit_card_id',
                'credit_card_invoice_id',
                'financial_import_id',
                'is_reconciled',
                'is_ignored',
            ]);

        $invoices = $workspace->creditCardInvoices()
            ->whereDate('reference_month', $monthStart->toDateString())
            ->get()
            ->keyBy('credit_card_id');

        $accountItems = $workspace->financialAccounts()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (FinancialAccount $account): array => $this->accountItem(
                $account,
                $period,
                $imports->where('financial_account_id', $account->id)->values(),
                $bankEntries->where('financial_account_id', $account->id)->values(),
                $closures->firstWhere('financial_account_id', $account->id),
            ));

        $cardItems = $workspace->creditCards()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CreditCard $card): array => $this->cardItem(
                $card,
                $period,
                $imports->where('credit_card_id', $card->id)->values(),
                $cardEntries->where('credit_card_id', $card->id)->values(),
                $invoices->get($card->id),
                $closures->firstWhere('credit_card_id', $card->id),
            ));

        $allItems = $accountItems
            ->concat($cardItems)
            ->values();
        $filteredItems = $this->filterItems($allItems, $view);

        return Inertia::render('imports/monthly-closing', [
            'period' => $periodKey,
            'view' => $view,
            'summary' => [
                'total' => $allItems->count(),
                'completed' => $allItems->whereIn('status', ['closed', 'no_movement'])->count(),
                'not_imported' => $allItems->where('status', 'not_imported')->count(),
                'pending_reconciliation' => $allItems->where('status', 'pending_reconciliation')->count(),
                'incomplete_period' => $allItems->where('period_complete', false)
                    ->where('kind', 'account')
                    ->where('has_import', true)
                    ->count(),
            ],
            'accounts' => $filteredItems->where('kind', 'account')->values()->all(),
            'cards' => $filteredItems->where('kind', 'card')->values()->all(),
            'counts' => [
                'all' => $allItems->count(),
                'pending' => $allItems->reject(fn (array $item): bool => in_array(
                    $item['status'],
                    ['closed', 'no_movement'],
                    true,
                ))->count(),
                'not_imported' => $allItems->where('status', 'not_imported')->count(),
                'reconciliation' => $allItems->where('status', 'pending_reconciliation')->count(),
                'reconciled' => $allItems->where('status', 'reconciled')->count(),
                'closed' => $allItems->whereIn('status', ['closed', 'no_movement'])->count(),
            ],
        ]);
    }

    public function close(Request $request, string $sourceType, int $source): RedirectResponse
    {
        return $this->saveClosure($request, $sourceType, $source, 'closed');
    }

    public function noMovement(Request $request, string $sourceType, int $source): RedirectResponse
    {
        return $this->saveClosure($request, $sourceType, $source, 'no_movement');
    }

    public function reopen(Request $request, string $sourceType, int $source): RedirectResponse
    {
        $workspace = $this->workspace();
        $period = $this->validatedPeriod($request);
        $this->source($workspace, $sourceType, $source);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $closure = $this->closureQuery($workspace, $sourceType, $source, $period)
            ->firstOrFail();
        $closure->update([
            'status' => 'open',
            'reopened_by' => $user->id,
            'reopened_at' => now(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Fechamento reaberto para conferência.',
        ]);

        return $this->returnToClosing($period);
    }

    private function saveClosure(
        Request $request,
        string $sourceType,
        int $source,
        string $status,
    ): RedirectResponse {
        $workspace = $this->workspace();
        $period = $this->validatedPeriod($request);
        $this->source($workspace, $sourceType, $source);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $attributes = [
            'workspace_id' => $workspace->id,
            'period_month' => $period->startOfMonth()->toDateString(),
            'financial_account_id' => $sourceType === 'account' ? $source : null,
            'credit_card_id' => $sourceType === 'card' ? $source : null,
        ];

        ImportPeriodClosure::query()->updateOrCreate($attributes, [
            'status' => $status,
            'closed_by' => $user->id,
            'closed_at' => now(),
            'reopened_by' => null,
            'reopened_at' => null,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $status === 'no_movement'
                ? 'Origem marcada como sem movimento no período.'
                : 'Período marcado como fechado.',
        ]);

        return $this->returnToClosing($period);
    }

    private function accountItem(
        FinancialAccount $account,
        CarbonImmutable $period,
        Collection $imports,
        Collection $entries,
        ?ImportPeriodClosure $closure,
    ): array {
        $pending = $entries->filter(fn ($entry): bool => ! $entry->is_reconciled && ! $entry->is_ignored)->count();
        $total = $entries->count();
        $hasImport = $imports->isNotEmpty();
        $periodComplete = $hasImport && $this->coversMonth($imports, $period);

        return [
            'id' => $account->id,
            'kind' => 'account',
            'name' => $account->name,
            'institution' => $account->institution,
            'subtitle' => collect([$account->agency ? 'Ag. '.$account->agency : null, $account->account_number ? 'Conta '.$account->account_number : null])->filter()->implode(' · '),
            'status' => $this->status($closure, $hasImport, $total, $pending),
            'has_import' => $hasImport,
            'period_complete' => $periodComplete,
            'period_start' => $imports->min(fn (FinancialImport $import): ?string => $import->statement_start_on?->toDateString()),
            'period_end' => $imports->max(fn (FinancialImport $import): ?string => $import->statement_end_on?->toDateString()),
            'total_items' => $total,
            'reconciled_items' => $total - $pending,
            'pending_items' => $pending,
            'invoice' => null,
            'imports' => $imports->map(fn (FinancialImport $import): array => $this->importDetail($import))->all(),
            'closure' => $this->closureData($closure),
        ];
    }

    private function cardItem(
        CreditCard $card,
        CarbonImmutable $period,
        Collection $imports,
        Collection $entries,
        mixed $invoice,
        ?ImportPeriodClosure $closure,
    ): array {
        $pending = $entries->filter(fn ($entry): bool => ! $entry->is_reconciled && ! $entry->is_ignored)->count();
        $total = $entries->count();
        $hasImport = $imports->isNotEmpty();

        return [
            'id' => $card->id,
            'kind' => 'card',
            'name' => $card->name,
            'institution' => $card->institution,
            'subtitle' => 'Final '.$card->last_four,
            'status' => $this->status($closure, $hasImport, $total, $pending),
            'has_import' => $hasImport,
            'period_complete' => $hasImport,
            'period_start' => null,
            'period_end' => null,
            'total_items' => $total,
            'reconciled_items' => $total - $pending,
            'pending_items' => $pending,
            'invoice' => $invoice === null ? null : [
                'reference_month' => $period->format('Y-m'),
                'due_date' => $invoice->due_date?->toDateString(),
                'amount' => $invoice->statement_amount ?? $invoice->calculated_amount,
            ],
            'imports' => $imports->map(fn (FinancialImport $import): array => $this->importDetail($import))->all(),
            'closure' => $this->closureData($closure),
        ];
    }

    private function status(
        ?ImportPeriodClosure $closure,
        bool $hasImport,
        int $total,
        int $pending,
    ): string {
        if ($closure?->status === 'closed') {
            return 'closed';
        }

        if ($closure?->status === 'no_movement') {
            return 'no_movement';
        }

        if (! $hasImport) {
            return 'not_imported';
        }

        if ($pending > 0) {
            return 'pending_reconciliation';
        }

        return $total > 0 ? 'reconciled' : 'imported';
    }

    private function coversMonth(Collection $imports, CarbonImmutable $period): bool
    {
        $targetStart = $period->startOfMonth();
        $targetEnd = $period->endOfMonth();
        $intervals = $imports
            ->filter(fn (FinancialImport $import): bool => $import->statement_start_on !== null && $import->statement_end_on !== null)
            ->map(fn (FinancialImport $import): array => [
                CarbonImmutable::parse($import->statement_start_on->toDateString()),
                CarbonImmutable::parse($import->statement_end_on->toDateString()),
            ])
            ->sortBy(fn (array $interval): string => $interval[0]->toDateString())
            ->values();

        if ($intervals->isEmpty() || $intervals[0][0]->greaterThan($targetStart)) {
            return false;
        }

        $coveredUntil = $intervals[0][1];

        foreach ($intervals->slice(1) as [$start, $end]) {
            if ($start->greaterThan($coveredUntil->addDay())) {
                return false;
            }

            if ($end->greaterThan($coveredUntil)) {
                $coveredUntil = $end;
            }
        }

        return $coveredUntil->greaterThanOrEqualTo($targetEnd);
    }

    private function importDetail(FinancialImport $import): array
    {
        $summary = is_array(data_get($import->metadata, 'processing_summary'))
            ? data_get($import->metadata, 'processing_summary')
            : null;

        return [
            'id' => $import->id,
            'filename' => $import->source_filename,
            'imported_at' => $import->imported_at?->toIso8601String(),
            'start_on' => $import->statement_start_on?->toDateString(),
            'end_on' => $import->statement_end_on?->toDateString(),
            'total_records' => $import->total_records,
            'processing_summary' => $summary,
        ];
    }

    private function closureData(?ImportPeriodClosure $closure): ?array
    {
        if ($closure === null || $closure->status === 'open') {
            return null;
        }

        return [
            'status' => $closure->status,
            'closed_at' => $closure->closed_at?->toIso8601String(),
            'closed_by' => $closure->closedBy?->name,
            'reopened_at' => $closure->reopened_at?->toIso8601String(),
            'reopened_by' => $closure->reopenedBy?->name,
        ];
    }

    private function filterItems(Collection $items, string $view): Collection
    {
        return match ($view) {
            'pending' => $items->reject(fn (array $item): bool => in_array($item['status'], ['closed', 'no_movement'], true))->values(),
            'not_imported' => $items->where('status', 'not_imported')->values(),
            'reconciliation' => $items->where('status', 'pending_reconciliation')->values(),
            'reconciled' => $items->where('status', 'reconciled')->values(),
            'closed' => $items->whereIn('status', ['closed', 'no_movement'])->values(),
            default => $items->values(),
        };
    }

    private function source(Workspace $workspace, string $sourceType, int $source): FinancialAccount|CreditCard
    {
        return match ($sourceType) {
            'account' => $workspace->financialAccounts()->where('is_active', true)->findOrFail($source),
            'card' => $workspace->creditCards()->where('is_active', true)->findOrFail($source),
            default => abort(404),
        };
    }

    private function closureQuery(
        Workspace $workspace,
        string $sourceType,
        int $source,
        CarbonImmutable $period,
    ) {
        return $workspace->importPeriodClosures()
            ->whereDate('period_month', $period->startOfMonth()->toDateString())
            ->when(
                $sourceType === 'account',
                fn ($query) => $query->where('financial_account_id', $source),
                fn ($query) => $query->where('credit_card_id', $source),
            );
    }

    private function validatedPeriod(Request $request): CarbonImmutable
    {
        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
        ]);

        return CarbonImmutable::createFromFormat('Y-m-d', $validated['period'].'-01')->startOfMonth();
    }

    private function period(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $value.'-01');

            if ($parsed instanceof CarbonImmutable) {
                return $parsed->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    private function view(mixed $value): string
    {
        return in_array($value, ['all', 'pending', 'not_imported', 'reconciliation', 'reconciled', 'closed'], true)
            ? $value
            : 'all';
    }

    private function returnToClosing(CarbonImmutable $period): RedirectResponse
    {
        return redirect()->to('/importacoes/fechamento-mensal?period='.$period->format('Y-m'));
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }
}
