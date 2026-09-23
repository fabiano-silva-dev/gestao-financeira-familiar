<?php

namespace App\Http\Controllers;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Http\Requests\ReassignFinancialImportRequest;
use App\Http\Requests\ResolveFinancialImportRequest;
use App\Http\Requests\StoreFinancialImportRequest;
use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\Detection\InstitutionMatcher;
use App\Services\Imports\FinancialDocumentImportService;
use App\Services\Imports\ImportedFileDestinationService;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        private readonly InstitutionMatcher $institutionMatcher,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $listing = ListingQuery::from(
            $request,
            [
                'filename',
                'target',
                'start',
                'end',
                'kind',
                'status',
                'reconciliation',
                'summary',
            ],
            'recent',
            'desc',
            ['kind', 'status'],
        );
        $importQuery = $workspace->financialImports()
            ->with([
                'financialAccount:id,name',
                'creditCard:id,name,last_four',
            ])
            ->withCount([
                'bankStatementEntries as bank_total',
                'bankStatementEntries as bank_resolved' => fn (Builder $query) => $query->where(
                    fn (Builder $resolved) => $resolved
                        ->where('is_reconciled', true)
                        ->orWhere('is_ignored', true),
                ),
                'cardStatementEntries as card_total',
                'cardStatementEntries as card_resolved' => fn (Builder $query) => $query->where(
                    fn (Builder $resolved) => $resolved
                        ->where('is_reconciled', true)
                        ->orWhere('is_ignored', true),
                ),
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

        $this->applyListingSort($importQuery, $listing);

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

        return Inertia::render('imports/index', [
            'accountOptions' => $workspace->financialAccounts()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'institution', 'agency', 'account_number', 'is_active'])
                ->map(fn (FinancialAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'institution' => $account->institution,
                    'institution_key' => $this->institutionKey($account->institution, $account->name),
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
                    'institution_key' => $this->institutionKey($card->institution, $card->name),
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
                    'value' => 'mercado_pago_account_statement',
                    'label' => 'Mercado Pago · extrato de conta',
                    'kind' => 'statement',
                ],
                [
                    'value' => 'mercado_pago_credit_card',
                    'label' => 'Mercado Pago · fatura de cartão',
                    'kind' => 'invoice',
                ],
            ],
            'imports' => $imports,
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
            'hasRecords' => $workspace->financialImports()->exists(),
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

    public function destroy(int $import): RedirectResponse
    {
        $workspace = $this->workspace();
        $pending = $workspace->financialImports()->findOrFail($import);

        $this->documentImportService->discardPending($workspace, $pending);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Documento aguardando confirmação cancelado.',
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

    private function institutionKey(?string $institution, string $name): ?string
    {
        if ($institution !== null) {
            $matchedInstitution = $this->institutionMatcher->detect($institution);

            if ($matchedInstitution !== null) {
                return $matchedInstitution;
            }
        }

        return $this->institutionMatcher->detect($name);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    /**
     * @param  Builder<\App\Models\FinancialImport>|\Illuminate\Database\Eloquent\Relations\Relation<\App\Models\FinancialImport, *, *>  $query
     */
    private function applyListingSort(Builder|Relation $query, ListingQuery $listing): void
    {
        if ($listing->sort === 'recent') {
            $query->latest('id');

            return;
        }

        $listing->applySort($query, [
            'filename' => 'source_filename',
            'kind' => 'type',
            'status' => 'status',
            'start' => 'statement_start_on',
            'end' => 'statement_end_on',
            'target' => function (Builder $builder, string $direction): void {
                $builder->orderByRaw(
                    'lower(coalesce(
                        (select financial_accounts.name from financial_accounts where financial_accounts.id = financial_imports.financial_account_id),
                        (select credit_cards.name from credit_cards where credit_cards.id = financial_imports.credit_card_id),
                        \'\'
                    )) '.$this->sortDirection($direction),
                );
            },
            'reconciliation' => function (Builder $builder, string $direction): void {
                $builder->orderByRaw(
                    $this->reconciliationRankSql().' '.$this->sortDirection($direction),
                );
            },
            'summary' => function (Builder $builder, string $direction): void {
                $builder->orderByRaw(
                    "coalesce((financial_imports.metadata->'processing_summary'->>'new_transactions_created')::integer, financial_imports.imported_records) ".$this->sortDirection($direction),
                );
            },
        ]);
    }

    private function sortDirection(string $direction): string
    {
        return $direction === 'asc' ? 'asc' : 'desc';
    }

    private function reconciliationStatus(
        FinancialImport $import,
        int $statementRecords,
        int $resolvedRecords,
    ): string {
        if (
            $import->status !== FinancialImportStatus::Completed
            || $import->type === FinancialImportType::Document
            || $statementRecords === 0
        ) {
            return 'unavailable';
        }

        $pendingRecords = $statementRecords - $resolvedRecords;

        if ($pendingRecords <= 0) {
            return 'reconciled';
        }

        if ($resolvedRecords === 0) {
            return 'pending';
        }

        return 'partial';
    }

    private function reconciliationRankSql(): string
    {
        $bankTotal = $this->entryCountSql('bank_statement_entries');
        $bankPending = $this->entryCountSql(
            'bank_statement_entries',
            'and is_reconciled = false and is_ignored = false',
        );
        $bankResolved = $this->entryCountSql(
            'bank_statement_entries',
            'and (is_reconciled = true or is_ignored = true)',
        );
        $cardTotal = $this->entryCountSql('card_statement_entries');
        $cardPending = $this->entryCountSql(
            'card_statement_entries',
            'and is_reconciled = false and is_ignored = false',
        );
        $cardResolved = $this->entryCountSql(
            'card_statement_entries',
            'and (is_reconciled = true or is_ignored = true)',
        );

        return "case
            when financial_imports.status <> 'completed' or financial_imports.type = 'document' then 0
            when financial_imports.type = 'card_statement' and {$cardTotal} = 0 then 0
            when financial_imports.type = 'ofx' and {$bankTotal} = 0 then 0
            when financial_imports.type = 'card_statement' and {$cardPending} = 0 then 1
            when financial_imports.type = 'ofx' and {$bankPending} = 0 then 1
            when financial_imports.type = 'card_statement' and {$cardResolved} = 0 then 3
            when financial_imports.type = 'ofx' and {$bankResolved} = 0 then 3
            else 2
        end";
    }

    private function entryCountSql(string $table, string $condition = ''): string
    {
        return "(select count(*) from {$table} where {$table}.financial_import_id = financial_imports.id {$condition})";
    }

    /** @return array<string, mixed> */
    private function importData(FinancialImport $import): array
    {
        $isInvoice = $import->type === FinancialImportType::CardStatement;
        $isDocument = $import->type === FinancialImportType::Document;
        $statementRecords = $isInvoice
            ? (int) ($import->card_total ?? 0)
            : ($isDocument ? 0 : (int) ($import->bank_total ?? 0));
        $resolvedRecords = $isInvoice
            ? (int) ($import->card_resolved ?? 0)
            : ($isDocument ? 0 : (int) ($import->bank_resolved ?? 0));
        $reconciliationStatus = $this->reconciliationStatus(
            $import,
            $statementRecords,
            $resolvedRecords,
        );
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
            'statement_records' => $statementRecords,
            'resolved_records' => $resolvedRecords,
            'reconciliation_status' => $reconciliationStatus,
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
}
