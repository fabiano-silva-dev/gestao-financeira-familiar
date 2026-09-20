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

class FinancialEntryService
{
    public function __construct(
        private readonly CardPurchaseService $cardPurchaseService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Workspace $workspace, array $data): FinancialTransaction
    {
        return DB::transaction(function () use ($workspace, $data): FinancialTransaction {
            $entry = $workspace->financialTransactions()->create([
                ...$this->entryData($data),
                'origin' => FinancialTransactionOrigin::Manual,
            ]);

            $this->syncMovement($entry);
            $this->syncInstallments($entry, $data);

            return $entry->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FinancialTransaction $entry, array $data): FinancialTransaction
    {
        return DB::transaction(function () use ($entry, $data): FinancialTransaction {
            $entry->update($this->entryData($data));
            $entry->refresh();
            $this->syncMovement($entry);
            $this->syncInstallments($entry, $data);

            return $entry->refresh();
        });
    }

    public function advanceStatus(FinancialTransaction $entry): FinancialTransaction
    {
        return DB::transaction(function () use ($entry): FinancialTransaction {
            $status = match ($entry->status) {
                FinancialTransactionStatus::Planned => FinancialTransactionStatus::Confirmed,
                FinancialTransactionStatus::Confirmed => FinancialTransactionStatus::Cancelled,
                FinancialTransactionStatus::Cancelled => FinancialTransactionStatus::Confirmed,
            };

            if (
                $status === FinancialTransactionStatus::Confirmed
                && $entry->credit_card_id === null
                && $entry->financial_account_id === null
            ) {
                abort(422, 'Informe uma conta antes de confirmar o lançamento.');
            }

            $entry->update(['status' => $status]);
            $entry->refresh();
            $this->cardPurchaseService->syncStatus($entry);

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function entryData(array $data): array
    {
        return [
            'type' => $data['type'],
            'transaction_date' => $data['transaction_date'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'financial_account_id' => $data['financial_account_id'] ?? null,
            'credit_card_id' => $data['credit_card_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'family_member_id' => $data['family_member_id'] ?? null,
            'payment_method' => $data['payment_method'],
            'payee_name' => $data['payee_name'] ?? null,
            'payment_instructions' => $data['payment_instructions'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncInstallments(FinancialTransaction $entry, array $data): void
    {
        if (
            $entry->type === FinancialTransactionType::Expense
            && $entry->credit_card_id !== null
        ) {
            $this->cardPurchaseService->sync(
                $entry,
                (int) ($data['installment_count'] ?? 1),
            );

            return;
        }

        $this->cardPurchaseService->clear($entry);
    }

    private function syncMovement(FinancialTransaction $entry): void
    {
        $movement = $entry->accountMovements()
            ->whereIn('type', [
                AccountMovementType::ExpensePayment,
                AccountMovementType::IncomeReceipt,
            ])
            ->first();

        if ($entry->financial_account_id === null || $entry->credit_card_id !== null) {
            $movement?->delete();

            return;
        }

        $isExpense = $entry->type === FinancialTransactionType::Expense;
        $data = [
            'workspace_id' => $entry->workspace_id,
            'financial_account_id' => $entry->financial_account_id,
            'occurred_on' => $entry->transaction_date,
            'description' => $entry->description,
            'amount' => $isExpense ? '-'.$entry->amount : $entry->amount,
            'type' => $isExpense
                ? AccountMovementType::ExpensePayment
                : AccountMovementType::IncomeReceipt,
            'is_reconciled' => false,
        ];

        $movement instanceof AccountMovement
            ? $movement->update($data)
            : $entry->accountMovements()->create($data);
    }
}
