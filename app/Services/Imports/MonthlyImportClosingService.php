<?php

namespace App\Services\Imports;

use App\Enums\FinancialImportStatus;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialPeriodClosure;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class MonthlyImportClosingService
{
    /**
     * @return array{
     *     accounts: list<array<string, mixed>>,
     *     cards: list<array<string, mixed>>,
     *     summary: array<string, int>
     * }
     */
    public function overview(Workspace $workspace, CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();
        $monthEnd = $month->endOfMonth();
        $period = $month->format('Y-m');

        $accounts = $workspace->financialAccounts()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $cards = $workspace->creditCards()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $closures = $workspace->financialPeriodClosures()
            ->with('closedBy:id,name')
            ->whereDate('reference_month', $month->toDateString())
            ->get()
            ->keyBy(fn (FinancialPeriodClosure $closure): string => $this->closureKey($closure));

        $bankImports = $workspace->financialImports()
            ->whereNotNull('financial_account_id')
            ->where('status', FinancialImportStatus::Completed->value)
            ->whereIn('financial_account_id', $accounts->pluck('id'))
            ->whereNotNull('statement_start_on')
            ->whereNotNull('statement_end_on')
            ->whereDate('statement_start_on', '<=', $monthEnd->toDateString())
            ->whereDate('statement_end_on', '>=', $month->toDateString())
            ->orderBy('imported_at')
            ->orderBy('id')
            ->get();

        $bankEntries = $workspace->bankStatementEntries()
            ->whereIn('financial_import_id', $bankImports->pluck('id'))
            ->whereBetween('occurred_on', [
                $month->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->get(['id', 'financial_import_id', 'financial_account_id', 'is_reconciled', 'is_ignored']);

        $cardImports = $workspace->financialImports()
            ->whereNotNull('credit_card_id')
            ->where('status', FinancialImportStatus::Completed->value)
            ->whereIn('credit_card_id', $cards->pluck('id'))
            ->orderBy('imported_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (FinancialImport $import): bool => data_get($import->metadata, 'reference_month') === $period)
            ->values();

        $invoices = $workspace->creditCardInvoices()
            ->whereIn('credit_card_id', $cards->pluck('id'))
            ->whereDate('reference_month', $month->toDateString())
            ->get()
            ->keyBy('credit_card_id');

        $cardEntries = $workspace->cardStatementEntries()
            ->whereIn('credit_card_invoice_id', $invoices->pluck('id'))
            ->get(['id', 'financial_import_id', 'credit_card_id', 'credit_card_invoice_id', 'is_reconciled', 'is_ignored']);

        $accountRows = $accounts
            ->map(fn (FinancialAccount $account): array => $this->accountRow(
                $account,
                $bankImports->where('financial_account_id', $account->id)->values(),
                $bankEntries->where('financial_account_id', $account->id)->values(),
                $closures->get("account:{$account->id}"),
                $month,
                $monthEnd,
            ))
            ->values();

        $cardRows = $cards
            ->map(function (CreditCard $card) use (
                $cardImports,
                $cardEntries,
                $closures,
                $invoices,
                $period,
            ): array {
                $invoice = $invoices->get($card->id);

                return $this->cardRow(
                    $card,
                    $cardImports->where('credit_card_id', $card->id)->values(),
                    $invoice instanceof CreditCardInvoice
                        ? $cardEntries->where('credit_card_invoice_id', $invoice->id)->values()
                        : collect(),
                    $invoice instanceof CreditCardInvoice ? $invoice : null,
                    $closures->get("card:{$card->id}"),
                    $period,
                );
            })
            ->values();

        $all = $accountRows->concat($cardRows);
        $completed = $all
            ->filter(fn (array $source): bool => in_array($source['status'], ['closed', 'no_movement'], true))
            ->count();
        $reconciled = $all->where('status', 'reconciled')->count();

        return [
            'accounts' => $accountRows->all(),
            'cards' => $cardRows->all(),
            'summary' => [
                'total' => $all->count(),
                'completed' => $completed,
                'pending_total' => $all->count() - $completed,
                'not_imported' => $all->where('status', 'not_imported')->count(),
                'pending_reconciliation' => $all->where('status', 'pending_reconciliation')->count(),
                'incomplete' => $all->where('status', 'imported')->count(),
                'ready_to_close' => $reconciled,
            ],
        ];
    }

    /**
     * @param Collection<int, FinancialImport> $imports
     * @param Collection<int, BankStatementEntry|CardStatementEntry> $entries
     * @return array<string, mixed>
     */
    private function accountRow(
        FinancialAccount $account,
        Collection $imports,
        Collection $entries,
        ?FinancialPeriodClosure $closure,
        CarbonImmutable $month,
        CarbonImmutable $monthEnd,
    ): array {
        $coverage = $this->coverage($imports, $month, $monthEnd);
        $counts = $this->entryCounts($entries);
        $status = $this->status(
            hasImports: $imports->isNotEmpty(),
            coverageComplete: $coverage['complete'],
            pending: $counts['pending'],
            closure: $closure,
            imports: $imports,
        );

        return [
            'id' => $account->id,
            'source_type' => 'account',
            'kind' => 'statement',
            'name' => $account->name,
            'institution' => $account->institution,
            'last_four' => null,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'coverage_start_on' => $coverage['start'],
            'coverage_end_on' => $coverage['end'],
            'coverage_complete' => $coverage['complete'],
            'reference_month' => $month->format('Y-m'),
            'due_date' => null,
            'statement_amount' => null,
            'total_items' => $counts['total'],
            'reconciled_items' => $counts['reconciled'],
            'ignored_items' => $counts['ignored'],
            'pending_items' => $counts['pending'],
            'import_count' => $imports->count(),
            'imports' => $imports->map(fn (FinancialImport $import): array => $this->importData($import))->all(),
            'can_close' => $status === 'reconciled',
            'closed_at' => in_array($status, ['closed', 'no_movement'], true)
                ? $closure?->closed_at?->toIso8601String()
                : null,
            'closed_by_name' => in_array($status, ['closed', 'no_movement'], true)
                ? $closure?->closedBy?->name
                : null,
            'closure_needs_review' => $closure !== null
                && ! in_array($status, ['closed', 'no_movement'], true),
        ];
    }

    /**
     * @param Collection<int, FinancialImport> $imports
     * @param Collection<int, BankStatementEntry|CardStatementEntry> $entries
     * @return array<string, mixed>
     */
    private function cardRow(
        CreditCard $card,
        Collection $imports,
        Collection $entries,
        ?CreditCardInvoice $invoice,
        ?FinancialPeriodClosure $closure,
        string $period,
    ): array {
        $counts = $this->entryCounts($entries);
        $status = $this->status(
            hasImports: $imports->isNotEmpty(),
            coverageComplete: true,
            pending: $counts['pending'],
            closure: $closure,
            imports: $imports,
        );
        $latestImport = $imports->last();
        $statementAmount = $invoice?->statement_amount
            ?? ($latestImport instanceof FinancialImport
                ? data_get($latestImport->metadata, 'statement_amount')
                : null)
            ?? $invoice?->calculated_amount;

        return [
            'id' => $card->id,
            'source_type' => 'card',
            'kind' => 'invoice',
            'name' => $card->name,
            'institution' => $card->institution,
            'last_four' => $card->last_four,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'coverage_start_on' => null,
            'coverage_end_on' => null,
            'coverage_complete' => true,
            'reference_month' => $period,
            'due_date' => $invoice?->due_date?->toDateString(),
            'statement_amount' => is_string($statementAmount) ? $statementAmount : null,
            'total_items' => $counts['total'],
            'reconciled_items' => $counts['reconciled'],
            'ignored_items' => $counts['ignored'],
            'pending_items' => $counts['pending'],
            'import_count' => $imports->count(),
            'imports' => $imports->map(fn (FinancialImport $import): array => $this->importData($import))->all(),
            'can_close' => $status === 'reconciled',
            'closed_at' => in_array($status, ['closed', 'no_movement'], true)
                ? $closure?->closed_at?->toIso8601String()
                : null,
            'closed_by_name' => in_array($status, ['closed', 'no_movement'], true)
                ? $closure?->closedBy?->name
                : null,
            'closure_needs_review' => $closure !== null
                && ! in_array($status, ['closed', 'no_movement'], true),
        ];
    }

    /**
     * @param Collection<int, FinancialImport> $imports
     * @return array{start: string|null, end: string|null, complete: bool}
     */
    private function coverage(
        Collection $imports,
        CarbonImmutable $month,
        CarbonImmutable $monthEnd,
    ): array {
        $ranges = $imports
            ->map(function (FinancialImport $import): ?array {
                if ($import->statement_start_on === null || $import->statement_end_on === null) {
                    return null;
                }

                return [
                    'start' => CarbonImmutable::parse($import->statement_start_on->toDateString()),
                    'end' => CarbonImmutable::parse($import->statement_end_on->toDateString()),
                ];
            })
            ->filter()
            ->sortBy(fn (array $range): int => $range['start']->getTimestamp())
            ->values();

        if ($ranges->isEmpty()) {
            return ['start' => null, 'end' => null, 'complete' => false];
        }

        $first = $ranges->first();
        $lastEnd = $ranges->max(fn (array $range): int => $range['end']->getTimestamp());
        $coverageEnd = CarbonImmutable::createFromTimestamp((int) $lastEnd);
        $cursor = $month;

        foreach ($ranges as $range) {
            $start = $range['start']->lt($month) ? $month : $range['start'];
            $end = $range['end']->gt($monthEnd) ? $monthEnd : $range['end'];

            if ($end->lt($month) || $start->gt($monthEnd)) {
                continue;
            }

            if ($start->gt($cursor)) {
                break;
            }

            if ($end->gte($cursor)) {
                $cursor = $end->addDay();
            }

            if ($cursor->gt($monthEnd)) {
                break;
            }
        }

        return [
            'start' => $first['start']->toDateString(),
            'end' => $coverageEnd->toDateString(),
            'complete' => $cursor->gt($monthEnd),
        ];
    }

    /**
     * @param Collection<int, BankStatementEntry|CardStatementEntry> $entries
     * @return array{total: int, reconciled: int, ignored: int, pending: int}
     */
    private function entryCounts(Collection $entries): array
    {
        $reconciled = $entries->where('is_reconciled', true)->count();
        $ignored = $entries
            ->filter(fn (BankStatementEntry|CardStatementEntry $entry): bool => ! $entry->is_reconciled && $entry->is_ignored)
            ->count();
        $pending = $entries
            ->filter(fn (BankStatementEntry|CardStatementEntry $entry): bool => ! $entry->is_reconciled && ! $entry->is_ignored)
            ->count();

        return [
            'total' => $entries->count(),
            'reconciled' => $reconciled,
            'ignored' => $ignored,
            'pending' => $pending,
        ];
    }

    /** @param Collection<int, FinancialImport> $imports */
    private function status(
        bool $hasImports,
        bool $coverageComplete,
        int $pending,
        ?FinancialPeriodClosure $closure,
        Collection $imports,
    ): string {
        if ($this->closureIsCurrent($closure, $imports) && $closure?->status === 'no_movement') {
            return 'no_movement';
        }

        if (! $hasImports) {
            return 'not_imported';
        }

        if ($pending > 0) {
            return 'pending_reconciliation';
        }

        if (! $coverageComplete) {
            return 'imported';
        }

        if ($this->closureIsCurrent($closure, $imports) && $closure?->status === 'closed') {
            return 'closed';
        }

        return 'reconciled';
    }

    /** @param Collection<int, FinancialImport> $imports */
    private function closureIsCurrent(
        ?FinancialPeriodClosure $closure,
        Collection $imports,
    ): bool {
        if ($closure === null) {
            return false;
        }

        return ! $imports->contains(function (FinancialImport $import) use ($closure): bool {
            $processedAt = $import->imported_at ?? $import->created_at;

            return $processedAt !== null && $processedAt->gt($closure->closed_at);
        });
    }

    /** @return array<string, mixed> */
    private function importData(FinancialImport $import): array
    {
        $summary = data_get($import->metadata, 'processing_summary');

        return [
            'id' => $import->id,
            'source_filename' => $import->source_filename,
            'imported_at' => $import->imported_at?->toIso8601String() ?? $import->created_at?->toIso8601String(),
            'statement_start_on' => $import->statement_start_on?->toDateString(),
            'statement_end_on' => $import->statement_end_on?->toDateString(),
            'total_records' => $import->total_records,
            'imported_records' => $import->imported_records,
            'duplicate_records' => $import->duplicate_records,
            'categorized_automatically' => is_array($summary)
                ? (int) ($summary['categorized_automatically'] ?? 0)
                : null,
            'automatically_reconciled' => is_array($summary)
                ? (int) ($summary['automatically_reconciled'] ?? 0)
                : null,
            'remaining_exceptions' => is_array($summary)
                ? (int) ($summary['remaining_exceptions'] ?? 0)
                : null,
        ];
    }

    private function closureKey(FinancialPeriodClosure $closure): string
    {
        if ($closure->financial_account_id !== null) {
            return "account:{$closure->financial_account_id}";
        }

        return "card:{$closure->credit_card_id}";
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'not_imported' => 'Não importado',
            'imported' => 'Importado',
            'pending_reconciliation' => 'Pendente de conciliação',
            'reconciled' => 'Conciliado',
            'closed' => 'Fechado',
            'no_movement' => 'Sem movimento',
            default => 'Pendente',
        };
    }
}
