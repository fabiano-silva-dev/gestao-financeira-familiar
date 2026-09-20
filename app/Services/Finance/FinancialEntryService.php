<?php

namespace App\Services\Finance;

use App\Enums\AccountMovementType;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\AccountMovement;
use App\Models\CreditCard;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
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

            if ($this->isInstallmentPurchase($entry)) {
                $this->createInstallments($entry);
            } else {
                $this->syncMovement($entry);
            }

            return $entry->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FinancialTransaction $entry, array $data): FinancialTransaction
    {
        abort_if(
            $entry->parent_transaction_id !== null,
            422,
            'Edite a compra parcelada pela transação principal.',
        );

        return DB::transaction(function () use ($entry, $data): FinancialTransaction {
            $hasSettledInstallment = $entry->installments()
                ->whereNotNull('settled_on')
                ->exists();

            abort_if(
                $hasSettledInstallment,
                422,
                'Não é possível alterar a estrutura de uma compra com parcelas já pagas.',
            );

            $entry->installments()->delete();
            $entry->update($this->entryData($data));
            $entry->refresh();

            if ($this->isInstallmentPurchase($entry)) {
                $this->syncMovement($entry);
                $this->createInstallments($entry);
            } else {
                $this->syncMovement($entry);
            }

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

            $entry->update(['status' => $status]);

            if ($this->isInstallmentPurchase($entry)) {
                $entry->installments()->update(['status' => $status->value]);

                $entry->installments()
                    ->get()
                    ->each(fn (FinancialTransaction $installment) => $this->syncMovement($installment));
            } else {
                $this->syncMovement($entry->refresh());
            }

            return $entry->refresh();
        });
    }

    public function toggleSettlement(FinancialTransaction $entry): FinancialTransaction
    {
        abort_if(
            $this->isInstallmentPurchase($entry),
            422,
            'A compra parcelada é liquidada parcela por parcela.',
        );
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

            return $entry->refresh();
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
        $installmentCount = max(1, (int) ($data['installment_count'] ?? 1));
        $isInstallmentPurchase = ($data['type'] ?? null) === FinancialTransactionType::Expense->value
            && $installmentCount > 1;

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
            'settled_on' => $isInstallmentPurchase
                ? null
                : (
                    array_key_exists('settled_on', $data)
                        ? $data['settled_on']
                        : ($isConfirmed && ! $usesCreditCard ? $data['transaction_date'] : null)
                ),
            'parent_transaction_id' => null,
            'installment_number' => null,
            'installment_count' => $isInstallmentPurchase ? $installmentCount : null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function createInstallments(FinancialTransaction $parent): void
    {
        $count = $parent->installment_count;

        if ($count === null || $count <= 1) {
            return;
        }

        $amounts = $this->splitAmount($parent->amount, $count);
        $firstDueDate = $this->firstInstallmentDueDate($parent);

        foreach ($amounts as $index => $amount) {
            $number = $index + 1;
            $dueMonth = $firstDueDate->startOfMonth()->addMonths($index);
            $dueDate = $dueMonth->day(min($firstDueDate->day, $dueMonth->daysInMonth));

            $installment = $parent->workspace->financialTransactions()->create([
                'type' => $parent->type,
                'transaction_date' => $parent->transaction_date,
                'competence_date' => $dueDate,
                'description' => "{$parent->description} ({$number}/{$count})",
                'amount' => $amount,
                'financial_account_id' => $parent->financial_account_id,
                'credit_card_id' => $parent->credit_card_id,
                'category_id' => $parent->category_id,
                'family_member_id' => $parent->family_member_id,
                'payment_method' => $parent->payment_method,
                'payee_name' => $parent->payee_name,
                'payment_instructions' => $parent->payment_instructions,
                'due_date' => $dueDate,
                'settled_on' => null,
                'parent_transaction_id' => $parent->id,
                'installment_number' => $number,
                'installment_count' => $count,
                'status' => $parent->status,
                'origin' => $parent->origin,
                'notes' => null,
            ]);

            $this->syncMovement($installment);
        }
    }

    private function firstInstallmentDueDate(FinancialTransaction $parent): CarbonImmutable
    {
        if ($parent->credit_card_id === null) {
            abort_if(
                $parent->due_date === null,
                422,
                'Informe o primeiro vencimento da compra parcelada.',
            );

            return CarbonImmutable::parse($parent->due_date->toDateString());
        }

        $card = CreditCard::query()->findOrFail($parent->credit_card_id);
        $purchaseDate = CarbonImmutable::parse($parent->transaction_date->toDateString());
        $closingDate = $purchaseDate
            ->startOfMonth()
            ->day(min($card->closing_day, $purchaseDate->daysInMonth));

        $monthOffset = $card->due_day > $card->closing_day ? 0 : 1;

        if ($purchaseDate->greaterThan($closingDate)) {
            $monthOffset++;
        }

        $dueMonth = $purchaseDate->startOfMonth()->addMonths($monthOffset);

        return $dueMonth->day(min($card->due_day, $dueMonth->daysInMonth));
    }

    /**
     * @return array<int, string>
     */
    private function splitAmount(string $amount, int $count): array
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $totalCents = ((int) $whole * 100) + (int) $fraction;
        $baseCents = intdiv($totalCents, $count);
        $remainder = $totalCents % $count;

        $amounts = array_fill(0, $count, $this->centsToDecimal($baseCents));
        $amounts[$count - 1] = $this->centsToDecimal($baseCents + $remainder);

        return $amounts;
    }

    private function centsToDecimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad(
            (string) ($cents % 100),
            2,
            '0',
            STR_PAD_LEFT,
        );
    }

    private function isInstallmentPurchase(FinancialTransaction $entry): bool
    {
        return $entry->parent_transaction_id === null
            && $entry->installment_count !== null
            && $entry->installment_count > 1;
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
            $this->isInstallmentPurchase($entry)
            || $entry->financial_account_id === null
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
