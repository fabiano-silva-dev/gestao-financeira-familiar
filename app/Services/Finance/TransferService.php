<?php

namespace App\Services\Finance;

use App\Enums\AccountMovementType;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Models\AccountMovement;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class TransferService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Workspace $workspace, array $data): FinancialTransaction
    {
        return DB::transaction(function () use ($workspace, $data): FinancialTransaction {
            $transfer = $workspace->financialTransactions()->create([
                ...$this->transferData($data),
                'type' => FinancialTransactionType::Transfer,
                'origin' => FinancialTransactionOrigin::Manual,
            ]);

            $this->syncMovements($transfer);

            return $transfer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FinancialTransaction $transfer, array $data): FinancialTransaction
    {
        return DB::transaction(function () use ($transfer, $data): FinancialTransaction {
            $transfer->update($this->transferData($data));
            $this->syncMovements($transfer->refresh());

            return $transfer;
        });
    }

    public function advanceStatus(FinancialTransaction $transfer): FinancialTransaction
    {
        $status = match ($transfer->status) {
            FinancialTransactionStatus::Planned => FinancialTransactionStatus::Confirmed,
            FinancialTransactionStatus::Confirmed => FinancialTransactionStatus::Cancelled,
            FinancialTransactionStatus::Cancelled => FinancialTransactionStatus::Confirmed,
        };

        $transfer->update(['status' => $status]);

        return $transfer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function transferData(array $data): array
    {
        return [
            'transaction_date' => $data['transaction_date'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'source_account_id' => $data['source_account_id'],
            'destination_account_id' => $data['destination_account_id'],
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function syncMovements(FinancialTransaction $transfer): void
    {
        $outgoing = $transfer->accountMovements()
            ->where('type', AccountMovementType::TransferOut)
            ->first();
        $incoming = $transfer->accountMovements()
            ->where('type', AccountMovementType::TransferIn)
            ->first();

        $common = [
            'workspace_id' => $transfer->workspace_id,
            'occurred_on' => $transfer->transaction_date,
            'description' => $transfer->description,
        ];

        $outgoingData = [
            ...$common,
            'financial_account_id' => $transfer->source_account_id,
            'amount' => '-'.$transfer->amount,
            'type' => AccountMovementType::TransferOut,
        ];
        $incomingData = [
            ...$common,
            'financial_account_id' => $transfer->destination_account_id,
            'amount' => $transfer->amount,
            'type' => AccountMovementType::TransferIn,
        ];

        $outgoing instanceof AccountMovement
            ? $outgoing->update($outgoingData)
            : $transfer->accountMovements()->create([
                ...$outgoingData,
                'is_reconciled' => false,
            ]);

        $incoming instanceof AccountMovement
            ? $incoming->update($incomingData)
            : $transfer->accountMovements()->create([
                ...$incomingData,
                'is_reconciled' => false,
            ]);
    }
}
