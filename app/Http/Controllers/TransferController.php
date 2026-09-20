<?php

namespace App\Http\Controllers;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Requests\UpdateTransferRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use App\Services\Finance\TransferService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly TransferService $transferService,
    ) {}

    public function index(): Response
    {
        $transfers = $this->workspace()
            ->financialTransactions()
            ->where('type', FinancialTransactionType::Transfer)
            ->with(['sourceAccount:id,name', 'destinationAccount:id,name'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (FinancialTransaction $transfer): array => $this->transferData($transfer));

        return Inertia::render('transfers/index', [
            'transfers' => $transfers,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('transfers/create', [
            'accountOptions' => $this->accountOptions(),
            'defaultDate' => now()->toDateString(),
        ]);
    }

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

        return to_route('transfers.index');
    }

    public function edit(int $transfer): Response
    {
        return Inertia::render('transfers/edit', [
            'transfer' => $this->transferData($this->findTransfer($transfer)),
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    public function update(
        UpdateTransferRequest $request,
        int $transfer,
    ): RedirectResponse {
        $this->transferService->update(
            $this->findTransfer($transfer),
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Transferência atualizada com sucesso.',
        ]);

        return to_route('transfers.index');
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

        return to_route('transfers.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findTransfer(int $transfer): FinancialTransaction
    {
        return $this->workspace()
            ->financialTransactions()
            ->where('type', FinancialTransactionType::Transfer)
            ->with(['sourceAccount:id,name', 'destinationAccount:id,name'])
            ->findOrFail($transfer);
    }

    /**
     * @return array<int, array{id: int, name: string, is_active: bool}>
     */
    private function accountOptions(): array
    {
        return $this->workspace()
            ->financialAccounts()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (FinancialAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'is_active' => $account->is_active,
            ])
            ->all();
    }

    /**
     * @return array{
     *     id: int,
     *     transaction_date: string,
     *     description: string,
     *     amount: string,
     *     source_account_id: int,
     *     source_account_name: string,
     *     destination_account_id: int,
     *     destination_account_name: string,
     *     status: string,
     *     status_label: string,
     *     notes: string|null
     * }
     */
    private function transferData(FinancialTransaction $transfer): array
    {
        return [
            'id' => $transfer->id,
            'transaction_date' => $transfer->transaction_date->toDateString(),
            'description' => $transfer->description,
            'amount' => $transfer->amount,
            'source_account_id' => $transfer->source_account_id,
            'source_account_name' => $transfer->sourceAccount->name,
            'destination_account_id' => $transfer->destination_account_id,
            'destination_account_name' => $transfer->destinationAccount->name,
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
            'notes' => $transfer->notes,
        ];
    }
}
