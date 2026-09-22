<?php

namespace App\Http\Controllers;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\FinancialImport;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class FinancialImportHistoryController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function __invoke(Request $request): Response
    {
        $workspace = $this->workspace();
        $search = trim((string) $request->query('search', ''));
        $kind = (string) $request->query('kind', '');
        $status = (string) $request->query('status', '');
        $sort = (string) $request->query('sort', 'imported_at');
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $account = $request->integer('account') ?: null;
        $card = $request->integer('card') ?: null;
        $period = $this->period($request->query('period'));

        $query = $workspace->financialImports()
            ->with([
                'financialAccount:id,name,institution',
                'creditCard:id,name,institution,last_four',
            ])
            ->withCount([
                'bankStatementEntries as bank_total',
                'bankStatementEntries as bank_resolved' => fn ($query) => $query->where(
                    fn ($resolved) => $resolved->where('is_reconciled', true)->orWhere('is_ignored', true),
                ),
                'cardStatementEntries as card_total',
                'cardStatementEntries as card_resolved' => fn ($query) => $query->where(
                    fn ($resolved) => $resolved->where('is_reconciled', true)->orWhere('is_ignored', true),
                ),
            ]);

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $term = '%'.$search.'%';
                $builder
                    ->where('source_filename', 'ilike', $term)
                    ->orWhereHas('financialAccount', fn ($account) => $account
                        ->where('name', 'ilike', $term)
                        ->orWhere('institution', 'ilike', $term))
                    ->orWhereHas('creditCard', fn ($card) => $card
                        ->where('name', 'ilike', $term)
                        ->orWhere('institution', 'ilike', $term));
            });
        }

        if ($kind === 'statement') {
            $query->where('type', FinancialImportType::Ofx->value);
        } elseif ($kind === 'invoice') {
            $query->where('type', FinancialImportType::CardStatement->value);
        } elseif ($kind === 'document') {
            $query->where('type', FinancialImportType::Document->value);
        }

        if (FinancialImportStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        if ($account !== null) {
            $query->where('financial_account_id', $account);
        }

        if ($card !== null) {
            $query->where('credit_card_id', $card);
        }

        if ($period !== null) {
            $start = $period->startOfMonth();
            $end = $period->endOfMonth();
            $periodKey = $period->format('Y-m');

            $query->where(function ($builder) use ($start, $end, $periodKey): void {
                $builder->where(function ($statement) use ($start, $end): void {
                    $statement
                        ->whereNotNull('financial_account_id')
                        ->whereDate('statement_start_on', '<=', $end->toDateString())
                        ->whereDate('statement_end_on', '>=', $start->toDateString());
                })->orWhere(function ($invoice) use ($periodKey): void {
                    $invoice
                        ->whereNotNull('credit_card_id')
                        ->where('metadata->reference_month', $periodKey);
                });
            });
        }

        $mapped = $query
            ->limit(250)
            ->get()
            ->map(fn (FinancialImport $import): array => $this->item($import));
        $mapped = $this->sort($mapped, $sort, $direction);

        return Inertia::render('imports/history', [
            'imports' => $mapped->values()->all(),
            'filters' => [
                'search' => $search,
                'kind' => $kind,
                'status' => $status,
                'sort' => $sort,
                'direction' => $direction,
                'account' => $account,
                'card' => $card,
                'period' => $period?->format('Y-m'),
            ],
            'kindOptions' => [
                ['value' => 'statement', 'label' => 'Extrato'],
                ['value' => 'invoice', 'label' => 'Fatura'],
                ['value' => 'document', 'label' => 'Documento pendente'],
            ],
            'statusOptions' => FinancialImportStatus::options(),
        ]);
    }

    /** @return array<string, mixed> */
    private function item(FinancialImport $import): array
    {
        $isInvoice = $import->type === FinancialImportType::CardStatement;
        $isDocument = $import->type === FinancialImportType::Document;
        $metadata = $import->metadata ?? [];
        $summary = is_array($metadata['processing_summary'] ?? null)
            ? $metadata['processing_summary']
            : null;
        $total = $isInvoice ? (int) ($import->card_total ?? 0) : (int) ($import->bank_total ?? 0);
        $resolved = $isInvoice ? (int) ($import->card_resolved ?? 0) : (int) ($import->bank_resolved ?? 0);

        return [
            'id' => $import->id,
            'financial_account_id' => $import->financial_account_id,
            'credit_card_id' => $import->credit_card_id,
            'kind' => $isDocument ? 'document' : ($isInvoice ? 'invoice' : 'statement'),
            'kind_label' => $import->type->label(),
            'source_filename' => $import->source_filename,
            'target_name' => $isDocument
                ? 'Identificação pendente'
                : ($isInvoice
                    ? trim(($import->creditCard?->name ?? 'Cartão').' · final '.($import->creditCard?->last_four ?? ''))
                    : ($import->financialAccount?->name ?? 'Conta')),
            'institution' => $isInvoice
                ? $import->creditCard?->institution
                : $import->financialAccount?->institution,
            'status' => $import->status->value,
            'status_label' => $import->status->label(),
            'reference_month' => is_string($metadata['reference_month'] ?? null)
                ? $metadata['reference_month']
                : null,
            'statement_start_on' => $import->statement_start_on?->toDateString(),
            'statement_end_on' => $import->statement_end_on?->toDateString(),
            'total_records' => $import->total_records,
            'resolved_records' => $resolved,
            'pending_records' => max(0, $total - $resolved),
            'imported_records' => $import->imported_records,
            'duplicate_records' => $import->duplicate_records,
            'processing_summary' => $summary,
            'error_message' => $import->error_message,
            'imported_at' => $import->imported_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    private function sort(Collection $items, string $sort, string $direction): Collection
    {
        $callback = match ($sort) {
            'filename' => fn (array $item): string => mb_strtolower($item['source_filename']),
            'institution' => fn (array $item): string => mb_strtolower($item['institution'] ?? ''),
            'target' => fn (array $item): string => mb_strtolower($item['target_name']),
            'kind' => fn (array $item): string => $item['kind'],
            'period' => fn (array $item): string => $item['reference_month'] ?? $item['statement_start_on'] ?? '',
            'status' => fn (array $item): string => $item['status'],
            'summary' => fn (array $item): int => $item['pending_records'],
            default => fn (array $item): string => $item['imported_at'] ?? $item['created_at'] ?? '',
        };

        return $direction === 'asc'
            ? $items->sortBy($callback, SORT_NATURAL | SORT_FLAG_CASE)
            : $items->sortByDesc($callback, SORT_NATURAL | SORT_FLAG_CASE);
    }

    private function period(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $period = CarbonImmutable::createFromFormat('Y-m-d', $value.'-01');

        return $period instanceof CarbonImmutable ? $period->startOfMonth() : null;
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }
}
