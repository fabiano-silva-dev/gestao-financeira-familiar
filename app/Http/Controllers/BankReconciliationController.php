<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBankReconciliationRequest;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Reconciliation\BankReconciliationService;
use App\Services\Reconciliation\BankReconciliationSuggestionService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly BankReconciliationService $reconciliationService,
        private readonly BankReconciliationSuggestionService $suggestionService,
    ) {}

    public function index(): Response
    {
        $workspace = $this->workspace();
        $pendingEntries = $workspace->bankStatementEntries()
            ->where('is_reconciled', false)
            ->with('financialAccount:id,name')
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        $movements = $workspace->accountMovements()
            ->where('is_reconciled', false)
            ->whereDoesntHave('bankStatementEntry')
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit(1000)
            ->get();
        $history = $workspace->bankStatementEntries()
            ->where('is_reconciled', true)
            ->whereNotNull('account_movement_id')
            ->with([
                'financialAccount:id,name',
                'accountMovement:id,description,occurred_on,amount,type',
                'reconciler:id,name',
            ])
            ->latest('reconciled_at')
            ->limit(20)
            ->get()
            ->map(fn (BankStatementEntry $entry): array => $this->historyData($entry));

        return Inertia::render('reconciliation/index', [
            'entries' => $pendingEntries
                ->map(fn (BankStatementEntry $entry): array => $this->pendingEntryData(
                    $entry,
                    $movements,
                ))
                ->all(),
            'history' => $history,
            'pendingEntriesCount' => $workspace->bankStatementEntries()
                ->where('is_reconciled', false)
                ->count(),
            'unmatchedMovementsCount' => $workspace->accountMovements()
                ->where('is_reconciled', false)
                ->whereDoesntHave('bankStatementEntry')
                ->count(),
            'reconciledEntriesCount' => $workspace->bankStatementEntries()
                ->where('is_reconciled', true)
                ->count(),
        ]);
    }

    public function store(
        StoreBankReconciliationRequest $request,
        int $entry,
    ): RedirectResponse {
        $workspace = $this->workspace();
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $movement = $workspace->accountMovements()
            ->findOrFail($request->integer('account_movement_id'));

        $this->reconciliationService->reconcile(
            $workspace,
            $this->findEntry($workspace, $entry),
            $movement,
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Movimento conciliado sem criar um novo lançamento.',
        ]);

        return to_route('reconciliation.index');
    }

    public function destroy(int $entry): RedirectResponse
    {
        $workspace = $this->workspace();
        $this->reconciliationService->undo(
            $workspace,
            $this->findEntry($workspace, $entry),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Conciliação desfeita. Os dois movimentos voltaram a ficar pendentes.',
        ]);

        return to_route('reconciliation.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findEntry(Workspace $workspace, int $entry): BankStatementEntry
    {
        return $workspace->bankStatementEntries()->findOrFail($entry);
    }

    /**
     * @param  Collection<int, AccountMovement>  $movements
     * @return array<string, mixed>
     */
    private function pendingEntryData(
        BankStatementEntry $entry,
        Collection $movements,
    ): array {
        return [
            'id' => $entry->id,
            'account_name' => $entry->financialAccount->name,
            'occurred_on' => $entry->occurred_on->toDateString(),
            'amount' => $entry->amount,
            'description' => $entry->description,
            'memo' => $entry->memo,
            'transaction_type' => $entry->transaction_type,
            'candidates' => $this->suggestionService->candidates($entry, $movements),
        ];
    }

    /** @return array<string, mixed> */
    private function historyData(BankStatementEntry $entry): array
    {
        $movement = $entry->accountMovement;

        return [
            'id' => $entry->id,
            'account_name' => $entry->financialAccount->name,
            'bank_description' => $entry->description,
            'bank_occurred_on' => $entry->occurred_on->toDateString(),
            'amount' => $entry->amount,
            'movement_description' => $movement?->description,
            'movement_occurred_on' => $movement?->occurred_on->toDateString(),
            'movement_type_label' => $movement?->type->label(),
            'reconciled_by_name' => $entry->reconciler?->name,
            'reconciled_at' => $entry->reconciled_at?->toIso8601String(),
        ];
    }
}
