<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClassifyReconciliationEntryRequest;
use App\Http\Requests\StoreCardStatementReconciliationRequest;
use App\Models\CardStatementEntry;
use App\Models\CreditCardInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Reconciliation\CardStatementReconciliationService;
use App\Services\Reconciliation\ReconciliationEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CardStatementReconciliationController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly CardStatementReconciliationService $reconciliationService,
        private readonly ReconciliationEntryService $entryActions,
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
        $this->entryActions->applyDraftToRelatedCard(
            $statementEntry->refresh()->load('transactionInstallment.transaction'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Linha da fatura conciliada sem criar uma nova despesa.',
        ]);

        return $this->redirectAfterCardReconciliation($invoice);
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

        return $this->redirectAfterCardReconciliation($invoice);
    }

    public function ignore(Request $request, int $invoice, int $entry): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspace();
        $this->entryActions->ignoreCardEntry(
            $workspace,
            $this->findEntry($this->findInvoice($workspace, $invoice), $entry),
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Linha ignorada. Ela não gera uma nova despesa.',
        ]);

        return $this->redirectAfterCardReconciliation($invoice);
    }

    public function restoreIgnored(int $invoice, int $entry): RedirectResponse
    {
        $workspace = $this->workspace();
        $creditCardInvoice = $this->findInvoice($workspace, $invoice);

        $this->entryActions->restoreCardEntry(
            $workspace,
            $this->findEntry($creditCardInvoice, $entry),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Linha restaurada para a conciliação.',
        ]);

        return $this->redirectAfterCardReconciliation($invoice);
    }

    public function classify(
        ClassifyReconciliationEntryRequest $request,
        int $invoice,
        int $entry,
    ): RedirectResponse {
        $workspace = $this->workspace();
        $this->entryActions->classifyCardEntry(
            $workspace,
            $this->findEntry($this->findInvoice($workspace, $invoice), $entry),
            $request->input('payee_name'),
            $request->filled('category_id') ? $request->integer('category_id') : null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Classificação atualizada sem alterar a origem da compra.',
        ]);

        return $this->redirectAfterCardReconciliation($invoice);
    }

    public function create(
        ClassifyReconciliationEntryRequest $request,
        int $invoice,
        int $entry,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspace();
        $model = $this->findEntry($this->findInvoice($workspace, $invoice), $entry);

        if ($request->has('category_id') || $request->has('payee_name')) {
            $model = $this->entryActions->classifyCardEntry(
                $workspace,
                $model,
                $request->input('payee_name'),
                $request->filled('category_id')
                    ? $request->integer('category_id')
                    : $model->suggested_category_id,
            );
        }

        $this->entryActions->createCardTransaction(
            $workspace,
            $model->load(['creditCard', 'invoice']),
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Compra criada e vinculada à linha da fatura, sem duplicar a despesa.',
        ]);

        return $this->redirectAfterCardReconciliation($invoice);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function redirectAfterCardReconciliation(int $invoice): RedirectResponse
    {
        $previousPath = parse_url((string) url()->previous(), PHP_URL_PATH) ?: '';

        if (str_contains($previousPath, '/conciliacao')) {
            return redirect()->to(url()->previous());
        }

        return to_route('credit-card-invoices.show', $invoice);
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
