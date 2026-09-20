<?php

namespace App\Services\Finance;

use App\Enums\AccountMovementType;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\AccountMovement;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class FinancialEntryService
{
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

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FinancialTransaction $entry, array $data): FinancialTransaction
    {
        return DB::transaction(function () use ($entry, $data): FinancialTransaction {
            $entry->update($this->entryData($data));
            $this->syncMovement($entry->refresh());

            return $entry;
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

            $entry->update(['status' => $status]);
            $this->syncMovement($entry->refresh());

            return $entry;
        });
    }

    public function toggleSettlement(FinancialTransaction $entry): FinancialTransaction
    {
        abort_if(
            $entry->credit_card_id !== null,
            422,
            'Compras no cartão são liquidadas pelo pagamento da fatura.',
        );
        abort_if(
            $entry->status === FinancialTransactionStatus::Cancelled,
            422,
            'Reative o lançamento antes de registrar o pagamento ou recebimento.',
        );

        return DB::transaction(function () use ($entry): FinancialTransaction {
            $entry->update([
                'status' => FinancialTransactionStatus::Confirmed,
                'settled_on' => $entry->settled_on === null
                    ? now()->toDateString()
                    : null,
            ]);

            $this->syncMovement($entry->refresh());

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function entryData(array $data): array
    {
        $isConfirmed = ($data['status'] ?? null) === FinancialTransactionStatus::Confirmed->value;
        $usesCreditCard = ($data['payment_method'] ?? null) === PaymentMethod::CreditCard->value;

        return [
            'type' => $data['type'],
            'transaction_date' => $data['transaction_date'],
            'competence_date' => $data['competence_date'] ?? $data['transaction_date'],
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
            'settled_on' => array_key_exists('settled_on', $data)
                ? $data['settled_on']
                : ($isConfirmed && ! $usesCreditCard ? $data['transaction_date'] : null),
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function syncMovement(FinancialTransaction $entry): void
    {
        $movement = $entry->accountMovements()
            ->whereIn('type', [
                AccountMovementType::ExpensePayment,
                AccountMovementType::IncomeReceipt,
            ])
            ->first();

        if (
            $entry->financial_account_id === null
            || $entry->credit_card_id !== null
            || $entry->status !== FinancialTransactionStatus::Confirmed
            || $entry->settled_on === null
        ) {
            $movement?->delete();

            return;
        }

        $isExpense = $entry->type === FinancialTransactionType::Expense;
        $data = [
            'workspace_id' => $entry->workspace_id,
            'financial_account_id' => $entry->financial_account_id,
            'occurred_on' => $entry->settled_on,
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
