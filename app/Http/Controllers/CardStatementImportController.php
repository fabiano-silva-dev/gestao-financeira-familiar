<?php

namespace App\Http\Controllers;

use App\Enums\FinancialImportType;
use App\Http\Requests\StoreCardStatementImportRequest;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\CardStatementImportService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CardStatementImportController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly CardStatementImportService $importService,
    ) {}

    public function index(): Response
    {
        $workspace = $this->workspace();
        $imports = $workspace->financialImports()
            ->where('type', FinancialImportType::CardStatement->value)
            ->with('creditCard:id,name,last_four')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (FinancialImport $import): array => $this->importData($import));
        $entries = $workspace->cardStatementEntries()
            ->with([
                'creditCard:id,name,last_four',
                'invoice:id,reference_month',
            ])
            ->latest('purchased_on')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (CardStatementEntry $entry): array => $this->entryData($entry));

        return Inertia::render('imports/card-statements', [
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
            'imports' => $imports,
            'entries' => $entries,
            'pendingEntriesCount' => $workspace->cardStatementEntries()
                ->where('is_reconciled', false)
                ->count(),
            'defaultReferenceMonth' => now()->format('Y-m'),
        ]);
    }

    public function store(StoreCardStatementImportRequest $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $card = $workspace->creditCards()
            ->findOrFail($request->integer('credit_card_id'));
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $result = $this->importService->import(
            $workspace,
            $card,
            $user,
            $request->file('file'),
            $request->string('reference_month')->toString(),
            $request->string('amount_sign')->toString(),
        );

        $message = $result->alreadyImported
            ? 'Esta fatura já havia sido importada. Nenhuma linha foi duplicada.'
            : sprintf(
                'Fatura processada: %d nova(s) e %d duplicada(s) ignorada(s).',
                $result->import->imported_records,
                $result->import->duplicate_records,
            );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return to_route('imports.card-statements.index');
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
        $metadata = $import->metadata ?? [];

        return [
            'id' => $import->id,
            'source_filename' => $import->source_filename,
            'card_name' => $import->creditCard->name,
            'card_last_four' => $import->creditCard->last_four,
            'status' => $import->status->value,
            'status_label' => $import->status->label(),
            'total_records' => $import->total_records,
            'imported_records' => $import->imported_records,
            'duplicate_records' => $import->duplicate_records,
            'statement_start_on' => $import->statement_start_on?->toDateString(),
            'statement_end_on' => $import->statement_end_on?->toDateString(),
            'reference_month' => $metadata['reference_month'] ?? null,
            'statement_amount' => $metadata['statement_amount'] ?? null,
            'statement_amount_applied' => $metadata['statement_amount_applied'] ?? null,
            'source_format' => $metadata['source_format'] ?? null,
            'error_message' => $import->error_message,
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function entryData(CardStatementEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'card_name' => $entry->creditCard->name,
            'card_last_four' => $entry->creditCard->last_four,
            'reference_month' => $entry->invoice->reference_month->toDateString(),
            'purchased_on' => $entry->purchased_on->toDateString(),
            'description' => $entry->description,
            'amount' => $entry->amount,
            'installment_number' => $entry->installment_number,
            'total_installments' => $entry->total_installments,
            'is_reconciled' => $entry->is_reconciled,
        ];
    }
}
