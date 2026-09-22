<?php

namespace App\Http\Controllers;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Requests\UpdateTransferRequest;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use App\Services\Finance\TransferService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TransferController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly TransferService $transferService,
    ) {}

    public function store(StoreTransferRequest $request): RedirectResponse
    {
        $this->transferService->create(
            $this->workspace(),
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Transferência cadastrada com sucesso.',
        ]);

        return to_route('transactions.index');
    }

    public function update(
        UpdateTransferRequest $request,
        int $transfer,
    ): RedirectResponse {
        $this->transferService->update(
            $this->findEntry($transfer),
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Transferência atualizada com sucesso.',
        ]);

        return to_route('transactions.index');
    }

    public function advanceStatus(int $transfer): RedirectResponse
    {
        $financialTransfer = $this->transferService->advanceStatus(
            $this->findTransfer($transfer),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($financialTransfer->status) {
                FinancialTransactionStatus::Confirmed => 'Transferência confirmada com sucesso.',
                FinancialTransactionStatus::Cancelled => 'Transferência cancelada com sucesso.',
                FinancialTransactionStatus::Planned => 'Transferência marcada como planejada.',
            },
        ]);

        return to_route('transactions.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findEntry(int $entry): FinancialTransaction
    {
        return $this->workspace()
            ->financialTransactions()
            ->whereIn('type', [
                FinancialTransactionType::Income,
                FinancialTransactionType::Expense,
                FinancialTransactionType::Transfer,
            ])
            ->findOrFail($entry);
    }

    private function findTransfer(int $transfer): FinancialTransaction
    {
        return $this->workspace()
            ->financialTransactions()
            ->where('type', FinancialTransactionType::Transfer)
            ->findOrFail($transfer);
    }
}
