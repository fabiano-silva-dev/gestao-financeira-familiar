<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCardStatementReconciliationRequest;
use App\Models\CardStatementEntry;
use App\Models\CreditCardInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Reconciliation\CardStatementReconciliationService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CardStatementReconciliationController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly CardStatementReconciliationService $reconciliationService,
    ) {}

    public function store(
        StoreCardStatementReconciliationRequest $request,
        int $invoice,
        int $entry,
    ): RedirectResponse {
        $workspace = $this->workspace();
        $creditCardInvoice = $this->findInvoice($workspace, $invoice);
        $statementEntry = $this->findEntry($creditCardInvoice, $entry);
        $installment = $creditCardInvoice->installments()
            ->findOrFail($request->integer('transaction_installment_id'));
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->reconciliationService->reconcile(
            $workspace,
            $creditCardInvoice,
            $statementEntry,
            $installment,
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Linha da fatura conciliada sem criar uma nova despesa.',
        ]);

        return to_route('credit-card-invoices.show', $invoice);
    }

    public function destroy(int $invoice, int $entry): RedirectResponse
    {
        $workspace = $this->workspace();
        $creditCardInvoice = $this->findInvoice($workspace, $invoice);

        $this->reconciliationService->undo(
            $workspace,
            $creditCardInvoice,
            $this->findEntry($creditCardInvoice, $entry),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Conciliação da linha da fatura desfeita.',
        ]);

        return to_route('credit-card-invoices.show', $invoice);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findInvoice(Workspace $workspace, int $invoice): CreditCardInvoice
    {
        return $workspace->creditCardInvoices()->findOrFail($invoice);
    }

    private function findEntry(
        CreditCardInvoice $invoice,
        int $entry,
    ): CardStatementEntry {
        return $invoice->statementEntries()->findOrFail($entry);
    }
}
