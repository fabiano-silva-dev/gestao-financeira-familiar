<?php

namespace App\Http\Controllers;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Http\Requests\StoreFinancialImportRequest;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\CardStatementImportService;
use App\Services\Imports\OfxImportService;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialImportController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly OfxImportService $ofxImportService,
        private readonly CardStatementImportService $cardStatementImportService,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $listing = ListingQuery::from(
            $request,
            ['description', 'date', 'target', 'status', 'amount', 'filename', 'kind'],
            'date',
            'desc',
            ['kind', 'status'],
        );
        $importQuery = $workspace->financialImports()
            ->with([
                'financialAccount:id,name',
                'creditCard:id,name,last_four',
            ]);
        $listing->applySearch($importQuery, ['source_filename']);

        $kind = $listing->filter('kind');

        if ($kind === 'statement') {
            $importQuery->where('type', FinancialImportType::Ofx);
        } elseif ($kind === 'invoice') {
            $importQuery->where('type', FinancialImportType::CardStatement);
        }

        $importStatus = $listing->filter('status');

        if ($importStatus !== null && FinancialImportStatus::tryFrom($importStatus) !== null) {
            $importQuery->where('status', $importStatus);
        }

        if (in_array($listing->sort, ['filename', 'kind', 'status'], true)) {
            $listing->applySort($importQuery, [
                'filename' => 'source_filename',
                'kind' => 'type',
                'status' => 'status',
            ]);
        } else {
            $importQuery->latest('id');
        }

        $imports = $importQuery
            ->limit(20)
            ->get()
            ->map(fn (FinancialImport $import): array => $this->importData($import));

        $bankQuery = $workspace->bankStatementEntries()->with('financialAccount:id,name');
        $cardQuery = $workspace->cardStatementEntries()->with([
            'creditCard:id,name,last_four',
            'invoice:id,reference_month',
        ]);

        if ($listing->search !== '') {
            $term = $listing->searchTerm();
            $bankQuery->where('description', 'ilike', $term);
            $cardQuery->where('description', 'ilike', $term);
        }

        if ($kind === 'invoice') {
            $bankQuery->whereRaw('1 = 0');
        } elseif ($kind === 'statement') {
            $cardQuery->whereRaw('1 = 0');
        }

        $entryStatus = $listing->filter('status');

        if ($entryStatus === 'completed') {
            $bankQuery->where('is_reconciled', true);
            $cardQuery->where('is_reconciled', true);
        } elseif ($entryStatus === 'processing') {
            $bankQuery->where('is_reconciled', false);
            $cardQuery->where('is_reconciled', false);
        } elseif ($entryStatus === 'failed') {
            $bankQuery->whereRaw('1 = 0');
            $cardQuery->whereRaw('1 = 0');
        }

        $bankEntries = $bankQuery
            ->latest('occurred_on')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (BankStatementEntry $entry): array => $this->bankEntryData($entry));
        $cardEntries = $cardQuery
            ->latest('purchased_on')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (CardStatementEntry $entry): array => $this->cardEntryData($entry));

        $entries = $listing->sortMapped(
            $bankEntries->concat($cardEntries),
            [
                'description' => fn (array $entry): string => $entry['description'],
                'date' => fn (array $entry): string => $entry['occurred_on'],
                'target' => fn (array $entry): string => $entry['target_name'],
                'status' => fn (array $entry): string => $entry['is_reconciled'] ? '1' : '0',
                'amount' => fn (array $entry): int => ListingQuery::moneyToCents($entry['amount']),
                'kind' => fn (array $entry): string => $entry['kind'],
            ],
        )->take(50)->values();

        return Inertia::render('imports/index', [
            'accountOptions' => $workspace->financialAccounts()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'institution', 'is_active'])
                ->map(fn (FinancialAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'institution' => $account->institution,
                    'is_active' => $account->is_active,
                ])
                ->all(),
            'cardOptions' => $workspace->creditCards()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'institution', 'last_four', 'is_active'])
                ->map(fn (CreditCard $card): array => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'institution' => $card->institution,
                    'last_four' => $card->last_four,
                    'is_active' => $card->is_active,
                ])
                ->all(),
            'pdfLayouts' => [
                [
                    'value' => 'banrisul_current_account',
                    'label' => 'Banrisul · conta corrente',
                    'kind' => 'statement',
                ],
                [
                    'value' => 'mercado_pago_credit_card',
                    'label' => 'Mercado Pago · fatura de cartão',
                    'kind' => 'invoice',
                ],
            ],
            'imports' => $imports,
            'entries' => $entries->all(),
            'pendingEntriesCount' => $workspace->bankStatementEntries()
                ->where('is_reconciled', false)
                ->count()
                + $workspace->cardStatementEntries()
                    ->where('is_reconciled', false)
                    ->count(),
            'defaultReferenceMonth' => now()->format('Y-m'),
            'filters' => $listing->toArray(),
            'hasRecords' => $workspace->financialImports()->exists()
                || $workspace->bankStatementEntries()->exists()
                || $workspace->cardStatementEntries()->exists(),
            'kindOptions' => [
                ['value' => 'statement', 'label' => 'Extrato'],
                ['value' => 'invoice', 'label' => 'Fatura'],
            ],
            'statusOptions' => FinancialImportStatus::options(),
        ]);
    }

    public function store(StoreFinancialImportRequest $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if ($request->string('kind')->toString() === 'invoice') {
            $card = $workspace->creditCards()
                ->findOrFail($request->integer('credit_card_id'));
            $result = $this->cardStatementImportService->import(
                $workspace,
                $card,
                $user,
                $request->file('file'),
                $request->string('reference_month')->toString(),
                $request->string('amount_sign')->toString(),
                $this->optionalPdfLayout($request->input('pdf_layout')),
            );
            $message = sprintf(
                'Fatura processada: %d nova(s) e %d duplicada(s) ignorada(s).',
                $result->import->imported_records,
                $result->import->duplicate_records,
            );
        } else {
            $account = $workspace->financialAccounts()
                ->findOrFail($request->integer('financial_account_id'));
            $result = $this->ofxImportService->import(
                $workspace,
                $account,
                $user,
                $request->file('file'),
            );
            $message = sprintf(
                'Extrato processado: %d novo(s) e %d duplicado(s) ignorado(s).',
                $result->import->imported_records,
                $result->import->duplicate_records,
            );
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return to_route('imports.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function optionalPdfLayout(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private function importData(FinancialImport $import): array
    {
        $isInvoice = $import->type === FinancialImportType::CardStatement;
        $metadata = $import->metadata ?? [];

        return [
            'id' => $import->id,
            'kind' => $isInvoice ? 'invoice' : 'statement',
            'kind_label' => $import->type->label(),
            'source_filename' => $import->source_filename,
            'target_name' => $isInvoice
                ? "{$import->creditCard?->name} · final {$import->creditCard?->last_four}"
                : $import->financialAccount?->name,
            'status' => $import->status->value,
            'status_label' => $import->status->label(),
            'total_records' => $import->total_records,
            'imported_records' => $import->imported_records,
            'duplicate_records' => $import->duplicate_records,
            'statement_start_on' => $import->statement_start_on?->toDateString(),
            'statement_end_on' => $import->statement_end_on?->toDateString(),
            'statement_amount' => $metadata['statement_amount'] ?? null,
            'statement_amount_applied' => $metadata['statement_amount_applied'] ?? null,
            'reference_month' => $metadata['reference_month'] ?? null,
            'source_format' => $metadata['source_format'] ?? null,
            'error_message' => $import->error_message,
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function bankEntryData(BankStatementEntry $entry): array
    {
        return [
            'id' => 'statement-'.$entry->id,
            'kind' => 'statement',
            'kind_label' => 'Extrato',
            'target_name' => $entry->financialAccount->name,
            'occurred_on' => $entry->occurred_on->toDateString(),
            'description' => $entry->description,
            'amount' => $entry->amount,
            'is_reconciled' => $entry->is_reconciled,
            'installment_label' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function cardEntryData(CardStatementEntry $entry): array
    {
        $installmentLabel = $entry->installment_number !== null && $entry->total_installments !== null
            ? "Parcela {$entry->installment_number}/{$entry->total_installments}"
            : null;

        return [
            'id' => 'invoice-'.$entry->id,
            'kind' => 'invoice',
            'kind_label' => 'Fatura',
            'target_name' => "{$entry->creditCard->name} · final {$entry->creditCard->last_four}",
            'occurred_on' => $entry->purchased_on->toDateString(),
            'description' => $entry->description,
            'amount' => $entry->amount,
            'is_reconciled' => $entry->is_reconciled,
            'installment_label' => $installmentLabel,
        ];
    }
}
