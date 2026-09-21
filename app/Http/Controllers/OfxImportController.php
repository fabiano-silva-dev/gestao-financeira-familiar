<?php

namespace App\Http\Controllers;

use App\Enums\FinancialImportType;
use App\Http\Requests\StoreOfxImportRequest;
use App\Models\BankStatementEntry;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\OfxImportService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OfxImportController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly OfxImportService $importService,
    ) {}

    public function index(): Response
    {
        $workspace = $this->workspace();
        $imports = $workspace->financialImports()
            ->where('type', FinancialImportType::Ofx->value)
            ->with('financialAccount:id,name')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (FinancialImport $import): array => $this->importData($import));
        $entries = $workspace->bankStatementEntries()
            ->with('financialAccount:id,name')
            ->latest('occurred_on')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (BankStatementEntry $entry): array => $this->entryData($entry));

        return Inertia::render('imports/ofx', [
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
            'imports' => $imports,
            'entries' => $entries,
            'pendingEntriesCount' => $workspace->bankStatementEntries()
                ->where('is_reconciled', false)
                ->count(),
        ]);
    }

    public function store(StoreOfxImportRequest $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $account = $workspace->financialAccounts()
            ->findOrFail($request->integer('financial_account_id'));
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $result = $this->importService->import(
            $workspace,
            $account,
            $user,
            $request->file('file'),
        );

        $message = $result->alreadyImported
            ? 'Este arquivo já havia sido importado. Nenhum movimento foi duplicado.'
            : sprintf(
                'OFX processado: %d novo(s) e %d duplicado(s) ignorado(s).',
                $result->import->imported_records,
                $result->import->duplicate_records,
            );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return to_route('imports.ofx.index');
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
        return [
            'id' => $import->id,
            'source_filename' => $import->source_filename,
            'account_name' => $import->financialAccount->name,
            'status' => $import->status->value,
            'status_label' => $import->status->label(),
            'total_records' => $import->total_records,
            'imported_records' => $import->imported_records,
            'duplicate_records' => $import->duplicate_records,
            'statement_start_on' => $import->statement_start_on?->toDateString(),
            'statement_end_on' => $import->statement_end_on?->toDateString(),
            'external_account_identifier' => $import->external_account_identifier,
            'error_message' => $import->error_message,
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function entryData(BankStatementEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'account_name' => $entry->financialAccount->name,
            'occurred_on' => $entry->occurred_on->toDateString(),
            'amount' => $entry->amount,
            'transaction_type' => $entry->transaction_type,
            'description' => $entry->description,
            'memo' => $entry->memo,
            'is_reconciled' => $entry->is_reconciled,
        ];
    }
}
