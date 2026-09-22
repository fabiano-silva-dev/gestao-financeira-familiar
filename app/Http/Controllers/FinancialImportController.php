<?php

namespace App\Http\Controllers;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Http\Requests\ReassignFinancialImportRequest;
use App\Http\Requests\ResolveFinancialImportRequest;
use App\Http\Requests\StoreFinancialImportRequest;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\FinancialDocumentImportService;
use App\Services\Imports\ImportedFileDestinationService;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

class FinancialImportController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly FinancialDocumentImportService $documentImportService,
        private readonly ImportedFileDestinationService $destinationService,
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
        } elseif ($kind === 'document') {
            $importQuery->where('type', FinancialImportType::Document);
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
            ->map(function (FinancialImport $import) use ($workspace): array {
                $import = $this->documentImportService->refreshPendingDetection(
                    $workspace,
                    $import,
                );

                return $this->importData($import);
            });

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
        } elseif ($kind === 'document') {
            $bankQuery->whereRaw('1 = 0');
            $cardQuery->whereRaw('1 = 0');
        }

        $entryStatus = $listing->filter('status');

        if ($entryStatus === 'completed') {
            $bankQuery->where('is_reconciled', true);
            $cardQuery->where('is_reconciled', true);
        } elseif ($entryStatus === 'processing') {
            $bankQuery->where('is_reconciled', false);
            $cardQuery->where('is_reconciled', false);
        } elseif (in_array($entryStatus, ['failed', 'needs_confirmation'], true)) {
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
                ->get(['id', 'name', 'institution', 'agency', 'account_number', 'is_active'])
                ->map(fn (FinancialAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'institution' => $account->institution,
                    'agency' => $account->agency,
                    'account_number' => $account->account_number,
                    'is_active' => $account->is_active,
                ])
                ->all(),
            'cardOptions' => $workspace->creditCards()
                ->with([
                    'holder:id,name',
                    'paymentAccount:id,name,institution,agency,account_number',
                ])
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'institution',
                    'last_four',
                    'holder_id',
                    'payment_account_id',
                    'is_active',
                ])
                ->map(fn (CreditCard $card): array => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'institution' => $card->institution,
                    'last_four' => $card->last_four,
                    'holder_name' => $card->holder?->name,
                    'payment_account_name' => $card->paymentAccount?->name,
                    'payment_account_institution' => $card->paymentAccount?->institution,
                    'payment_account_agency' => $card->paymentAccount?->agency,
                    'payment_account_number' => $card->paymentAccount?->account_number,
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
                    ->count()
                + $workspace->financialImports()
                    ->where('status', FinancialImportStatus::NeedsConfirmation->value)
                    ->count(),
            'defaultReferenceMonth' => now()->format('Y-m'),
            'filters' => $listing->toArray(),
            'hasRecords' => $workspace->financialImports()->exists()
                || $workspace->bankStatementEntries()->exists()
                || $workspace->cardStatementEntries()->exists(),
            'kindOptions' => [
                ['value' => 'statement', 'label' => 'Extrato'],
                ['value' => 'invoice', 'label' => 'Fatura'],
                ['value' => 'document', 'label' => 'Documento pendente'],
            ],
            'statusOptions' => FinancialImportStatus::options(),
        ]);
    }

    public function store(StoreFinancialImportRequest $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $files = $request->file('files', []);
        $files = is_array($files) ? $files : [$files];

        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }

        $files = array_values(array_filter($files));
        $processed = 0;
        $pending = 0;
        $duplicates = 0;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $outcome = $this->documentImportService->import($workspace, $user, $file);

            match ($outcome['status']) {
                'processed' => $processed++,
                'duplicate' => $duplicates++,
                default => $pending++,
            };
        }

        Inertia::flash('toast', [
            'type' => $pending > 0 ? 'warning' : 'success',
            'message' => sprintf(
                '%d arquivo(s) processado(s), %d aguardando confirmação e %d duplicado(s) ignorado(s).',
                $processed,
                $pending,
                $duplicates,
            ),
        ]);

        return to_route('imports.index');
    }

    public function resolve(
        ResolveFinancialImportRequest $request,
        int $import,
    ): RedirectResponse {
        $workspace = $this->workspace();
        $pending = $workspace->financialImports()->findOrFail($import);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->documentImportService->resolvePending(
            $workspace,
            $pending,
            $user,
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Documento identificado, importado e processado.',
        ]);

        return to_route('imports.index');
    }

    public function reassign(
        ReassignFinancialImportRequest $request,
        int $import,
    ): RedirectResponse {
        $workspace = $this->workspace();
        $financialImport = $workspace->financialImports()->findOrFail($import);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->destinationService->reassign(
            $workspace,
            $financialImport,
            $user,
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Destino da importação atualizado.',
        ]);

        return to_route('imports.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    /** @return array<string, mixed> */
    private function importData(FinancialImport $import): array
    {
        $isInvoice = $import->type === FinancialImportType::CardStatement;
        $isDocument = $import->type === FinancialImportType::Document;
        $metadata = $import->metadata ?? [];
        $detection = is_array($metadata['autodetection'] ?? null)
            ? $metadata['autodetection']
            : null;

        return [
            'id' => $import->id,
            'financial_account_id' => $import->financial_account_id,
            'credit_card_id' => $import->credit_card_id,
            'can_reassign' => $import->status === FinancialImportStatus::Completed
                && in_array($import->type, [
                    FinancialImportType::Ofx,
                    FinancialImportType::CardStatement,
                ], true),
            'kind' => $isDocument ? 'document' : ($isInvoice ? 'invoice' : 'statement'),
            'kind_label' => $import->type->label(),
            'source_filename' => $import->source_filename,
            'target_name' => $isDocument
                ? $this->detectionTargetName($detection)
                : ($isInvoice
                    ? "{$import->creditCard?->name} · final {$import->creditCard?->last_four}"
                    : $import->financialAccount?->name),
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
            'processing_summary' => is_array($metadata['processing_summary'] ?? null)
                ? $metadata['processing_summary']
                : null,
            'autodetection' => $detection,
            'missing_fields' => is_array($metadata['missing_fields'] ?? null)
                ? $metadata['missing_fields']
                : [],
            'error_message' => $import->error_message,
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed>|null $detection */
    private function detectionTargetName(?array $detection): string
    {
        if ($detection === null) {
            return 'Identificação pendente';
        }

        $institution = is_string($detection['institution'] ?? null)
            ? str_replace('_', ' ', $detection['institution'])
            : 'instituição não identificada';
        $type = match ($detection['document_type'] ?? null) {
            'bank_statement' => 'extrato bancário',
            'payment_account_statement' => 'extrato de conta de pagamento',
            'credit_card_statement' => 'fatura de cartão',
            'proof' => 'comprovante',
            default => 'tipo não identificado',
        };

        return ucfirst($institution).' · '.$type;
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
