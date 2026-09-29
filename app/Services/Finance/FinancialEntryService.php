<?php

namespace App\Services\Finance;

use App\Enums\AccountMovementType;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionInstallmentStatus;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\Workspace;
use App\Services\Reconciliation\BankReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialEntryService
{
    public function __construct(
        private readonly CardPurchaseService $cardPurchaseService,
        private readonly BankReconciliationService $bankReconciliation,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        Workspace $workspace,
        array $data,
        FinancialTransactionOrigin $origin = FinancialTransactionOrigin::Manual,
    ): FinancialTransaction {
        return DB::transaction(function () use ($workspace, $data, $origin): FinancialTransaction {
            $entry = $workspace->financialTransactions()->create([
                ...$this->entryData($data),
                'origin' => $origin,
            ]);

            $this->syncInstallments($entry, $data);
            $this->syncMovement($entry);

            return $entry->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FinancialTransaction $entry, array $data): FinancialTransaction
    {
        return DB::transaction(function () use ($entry, $data): FinancialTransaction {
            if ($entry->type === FinancialTransactionType::Transfer) {
                $this->clearTransferMovements($entry);
            }

            $entry->update($this->entryData($data, $entry));

            if ($entry->financial_recurrence_id !== null) {
                $entry->update(['recurrence_is_overridden' => true]);
            }

            $entry->refresh();
            $this->syncInstallments($entry, $data);
            $this->syncMovement($entry);

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

            $this->syncCashInstallmentStatus($entry);
            $this->syncMovement($entry);
            $this->cardPurchaseService->syncStatus($entry);

            return $entry->refresh();
        });
    }

    public function settle(FinancialTransaction $entry, string $settledOn): FinancialTransaction
    {
        return DB::transaction(function () use ($entry, $settledOn): FinancialTransaction {
            $entry->update([
                'status' => FinancialTransactionStatus::Confirmed,
                'settled_on' => $settledOn,
            ]);
            $this->syncMovement($entry->refresh());

            return $entry->refresh();
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
        abort_if(
            $entry->financial_account_id === null,
            422,
            'Informe uma conta antes de registrar o pagamento ou recebimento.',
        );
        abort_if(
            $entry->installments()
                ->whereNull('credit_card_invoice_id')
                ->exists(),
            422,
            'Este lançamento possui parcelas. O pagamento deve ser registrado na parcela correspondente.',
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

    public function revertRecurrenceSettlement(
        FinancialTransaction $entry,
    ): FinancialTransaction {
        if ($entry->financial_recurrence_id === null) {
            throw ValidationException::withMessages([
                'settlement' => 'Este lançamento não pertence a uma recorrência.',
            ]);
        }

        if ($entry->credit_card_id !== null) {
            throw ValidationException::withMessages([
                'settlement' => 'Compras no cartão são liquidadas pelo pagamento da fatura.',
            ]);
        }

        if ($entry->settled_on === null) {
            throw ValidationException::withMessages([
                'settlement' => 'Esta ocorrência já está pendente.',
            ]);
        }

        if ($entry->refunds()->exists()) {
            throw ValidationException::withMessages([
                'settlement' => 'Remova os reembolsos antes de excluir este lançamento.',
            ]);
        }

        return DB::transaction(function () use ($entry): FinancialTransaction {
            $workspace = $entry->workspace()->firstOrFail();
            $entry->load('accountMovements.bankStatementEntry');

            foreach ($entry->accountMovements as $movement) {
                $statement = $movement->bankStatementEntry;

                if ($statement instanceof BankStatementEntry) {
                    $this->bankReconciliation->undo($workspace, $statement);
                }
            }

            $entry->update([
                'status' => FinancialTransactionStatus::Planned,
                'settled_on' => null,
                'due_date' => $entry->due_date?->toDateString()
                    ?? $entry->recurrence_occurrence_date?->toDateString()
                    ?? $entry->transaction_date->toDateString(),
            ]);

            $this->syncMovement($entry->refresh());

            return $entry->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function entryData(
        array $data,
        ?FinancialTransaction $existing = null,
    ): array {
        $isConfirmed = ($data['status'] ?? null) === FinancialTransactionStatus::Confirmed->value;
        $usesCreditCard = ($data['payment_method'] ?? null) === PaymentMethod::CreditCard->value;
        $installmentCount = max(1, (int) ($data['installment_count'] ?? 1));
        $isCashInstallmentPlan = ($data['type'] ?? null) === FinancialTransactionType::Expense->value
            && ! $usesCreditCard
            && $installmentCount > 1;

        return [
            'type' => $data['type'],
            'transaction_date' => $data['transaction_date'],
            'competence_date' => $data['competence_date'] ?? $data['transaction_date'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'financial_account_id' => $data['financial_account_id'] ?? null,
            'source_account_id' => null,
            'destination_account_id' => null,
            'credit_card_id' => $data['credit_card_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'family_member_id' => $data['family_member_id'] ?? null,
            'payment_method' => $data['payment_method'],
            'payee_name' => $data['payee_name'] ?? null,
            'payment_instructions' => $data['payment_instructions'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'settled_on' => $isCashInstallmentPlan
                ? null
                : (array_key_exists('settled_on', $data)
                    ? $data['settled_on']
                    : ($isConfirmed && ! $usesCreditCard ? $data['transaction_date'] : null)),
            'financial_recurrence_id' => $data['financial_recurrence_id']
                ?? $existing?->financial_recurrence_id,
            'recurrence_occurrence_date' => $data['recurrence_occurrence_date']
                ?? $existing?->recurrence_occurrence_date,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncInstallments(FinancialTransaction $entry, array $data): void
    {
        $installmentCount = max(1, (int) ($data['installment_count'] ?? 1));
        $isExpense = $entry->type === FinancialTransactionType::Expense;
        $usesCreditCard = $entry->credit_card_id !== null;

        if ($isExpense && $usesCreditCard) {
            $this->clearCashInstallments($entry);

            if ($entry->status === FinancialTransactionStatus::Confirmed) {
                $this->cardPurchaseService->sync($entry, $installmentCount);
            } else {
                $this->cardPurchaseService->clear($entry);
            }

            return;
        }

        $this->cardPurchaseService->clear($entry);

        if ($isExpense && ! $usesCreditCard && $installmentCount > 1) {
            $this->syncCashInstallments($entry, $data, $installmentCount);

            return;
        }

        $this->clearCashInstallments($entry);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncCashInstallments(
        FinancialTransaction $entry,
        array $data,
        int $installmentCount,
    ): void {
        $existing = $entry->installments()
            ->whereNull('credit_card_invoice_id')
            ->with('accountMovement.bankStatementEntry')
            ->orderBy('installment_number')
            ->lockForUpdate()
            ->get();

        $amounts = $this->splitAmount((string) $entry->amount, $installmentCount);
        $dueBase = CarbonImmutable::parse(
            (string) ($data['due_date'] ?? $entry->due_date?->toDateString() ?? $entry->transaction_date->toDateString()),
        );
        $competenceBase = CarbonImmutable::parse(
            $entry->competence_date?->toDateString()
                ?? $entry->transaction_date->toDateString(),
        )->startOfMonth();
        $paidAt = $entry->status === FinancialTransactionStatus::Confirmed
            && filled($data['settled_on'] ?? null)
                ? CarbonImmutable::parse((string) $data['settled_on'])
                : null;

        if ($this->cashInstallmentStructureMatches(
            $existing,
            $amounts,
            $dueBase,
            $competenceBase,
            $paidAt,
            $entry->status,
        )) {
            return;
        }

        $this->assertCashInstallmentsEditable($existing);

        foreach ($existing as $installment) {
            $installment->accountMovement?->delete();
        }

        $entry->installments()
            ->whereNull('credit_card_invoice_id')
            ->delete();

        foreach ($amounts as $index => $amount) {
            $number = $index + 1;
            $dueDate = $this->dateInMonth(
                $dueBase->startOfMonth()->addMonths($index),
                $dueBase->day,
            );
            $status = $entry->status === FinancialTransactionStatus::Cancelled
                ? TransactionInstallmentStatus::Cancelled
                : ($number === 1 && $paidAt !== null
                    ? TransactionInstallmentStatus::Paid
                    : TransactionInstallmentStatus::Open);

            $entry->installments()->create([
                'workspace_id' => $entry->workspace_id,
                'credit_card_invoice_id' => null,
                'installment_number' => $number,
                'total_installments' => $installmentCount,
                'amount' => $amount,
                'competence_month' => $competenceBase
                    ->addMonths($index)
                    ->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'expected_payment_date' => $dueDate->toDateString(),
                'paid_at' => $status === TransactionInstallmentStatus::Paid
                    ? $paidAt?->toDateString()
                    : null,
                'status' => $status,
            ]);
        }
    }

    private function clearCashInstallments(FinancialTransaction $entry): void
    {
        $installments = $entry->installments()
            ->whereNull('credit_card_invoice_id')
            ->with('accountMovement.bankStatementEntry')
            ->lockForUpdate()
            ->get();

        if ($installments->isEmpty()) {
            return;
        }

        $this->assertCashInstallmentsEditable($installments);

        foreach ($installments as $installment) {
            $installment->accountMovement?->delete();
        }

        $entry->installments()
            ->whereNull('credit_card_invoice_id')
            ->delete();
    }

    /**
     * @param  Collection<int, TransactionInstallment>  $installments
     */
    private function assertCashInstallmentsEditable(Collection $installments): void
    {
        $hasPaid = $installments->contains(
            fn (TransactionInstallment $installment): bool => $installment->status === TransactionInstallmentStatus::Paid,
        );
        $hasReconciledMovement = $installments->contains(
            fn (TransactionInstallment $installment): bool => $installment->accountMovement?->bankStatementEntry !== null,
        );

        if ($hasPaid || $hasReconciledMovement) {
            throw ValidationException::withMessages([
                'installment_count' => 'O parcelamento já possui parcela paga ou conciliada. Desfaça o pagamento ou a conciliação antes de alterar valor, datas ou quantidade de parcelas.',
            ]);
        }
    }

    /**
     * @param  Collection<int, TransactionInstallment>  $installments
     * @param  array<int, string>  $amounts
     */
    private function cashInstallmentStructureMatches(
        Collection $installments,
        array $amounts,
        CarbonImmutable $dueBase,
        CarbonImmutable $competenceBase,
        ?CarbonImmutable $paidAt,
        FinancialTransactionStatus $transactionStatus,
    ): bool {
        if ($installments->count() !== count($amounts)) {
            return false;
        }

        foreach ($amounts as $index => $amount) {
            $installment = $installments->values()->get($index);

            if (! $installment instanceof TransactionInstallment) {
                return false;
            }

            $number = $index + 1;
            $dueDate = $this->dateInMonth(
                $dueBase->startOfMonth()->addMonths($index),
                $dueBase->day,
            );
            $expectedStatus = $transactionStatus === FinancialTransactionStatus::Cancelled
                ? TransactionInstallmentStatus::Cancelled
                : ($number === 1 && $paidAt !== null
                    ? TransactionInstallmentStatus::Paid
                    : TransactionInstallmentStatus::Open);
            $expectedPaidAt = $expectedStatus === TransactionInstallmentStatus::Paid
                ? $paidAt?->toDateString()
                : null;

            if (
                $installment->installment_number !== $number
                || $installment->total_installments !== count($amounts)
                || $installment->amount !== $amount
                || $installment->competence_month->toDateString() !== $competenceBase->addMonths($index)->toDateString()
                || $installment->due_date->toDateString() !== $dueDate->toDateString()
                || $installment->expected_payment_date?->toDateString() !== $dueDate->toDateString()
                || $installment->paid_at?->toDateString() !== $expectedPaidAt
                || $installment->status !== $expectedStatus
            ) {
                return false;
            }
        }

        return true;
    }

    private function syncCashInstallmentStatus(FinancialTransaction $entry): void
    {
        $installments = $entry->installments()
            ->whereNull('credit_card_invoice_id')
            ->lockForUpdate()
            ->get();

        if ($installments->isEmpty()) {
            return;
        }

        if ($entry->status === FinancialTransactionStatus::Cancelled) {
            if ($installments->contains(
                fn (TransactionInstallment $installment): bool => $installment->status === TransactionInstallmentStatus::Paid,
            )) {
                throw ValidationException::withMessages([
                    'status' => 'Não é possível cancelar um parcelamento com parcelas pagas.',
                ]);
            }

            $entry->installments()
                ->whereNull('credit_card_invoice_id')
                ->update([
                    'status' => TransactionInstallmentStatus::Cancelled->value,
                    'paid_at' => null,
                ]);

            return;
        }

        $entry->installments()
            ->whereNull('credit_card_invoice_id')
            ->where('status', TransactionInstallmentStatus::Cancelled->value)
            ->update(['status' => TransactionInstallmentStatus::Open->value]);
    }

    private function clearTransferMovements(FinancialTransaction $entry): void
    {
        $entry->accountMovements()
            ->whereIn('type', [
                AccountMovementType::TransferOut,
                AccountMovementType::TransferIn,
            ])
            ->get()
            ->each(fn (AccountMovement $movement) => $movement->delete());
    }

    private function syncMovement(FinancialTransaction $entry): void
    {
        $cashInstallments = $entry->installments()
            ->whereNull('credit_card_invoice_id')
            ->orderBy('installment_number')
            ->get();

        if ($cashInstallments->isNotEmpty()) {
            $ordinaryMovement = $entry->accountMovements()
                ->whereNull('transaction_installment_id')
                ->whereIn('type', [
                    AccountMovementType::ExpensePayment,
                    AccountMovementType::IncomeReceipt,
                ])
                ->first();
            $ordinaryMovement?->delete();

            $linkedMovements = $entry->accountMovements()
                ->whereNotNull('transaction_installment_id')
                ->get()
                ->keyBy('transaction_installment_id');

            foreach ($cashInstallments as $installment) {
                $movement = $linkedMovements->get($installment->id);
                $shouldExist = $entry->type === FinancialTransactionType::Expense
                    && $entry->financial_account_id !== null
                    && $entry->status !== FinancialTransactionStatus::Cancelled
                    && $installment->status === TransactionInstallmentStatus::Paid
                    && $installment->paid_at !== null;

                if (! $shouldExist) {
                    if ($movement instanceof AccountMovement) {
                        $movement->delete();
                    }

                    continue;
                }

                $movementData = [
                    'workspace_id' => $entry->workspace_id,
                    'transaction_installment_id' => $installment->id,
                    'financial_account_id' => $entry->financial_account_id,
                    'occurred_on' => $installment->paid_at,
                    'description' => $entry->description
                        .' · Parcela '.$installment->installment_number
                        .'/'.$installment->total_installments,
                    'amount' => '-'.$installment->amount,
                    'type' => AccountMovementType::ExpensePayment,
                ];

                $movement instanceof AccountMovement
                    ? $movement->update($movementData)
                    : $entry->accountMovements()->create([
                        ...$movementData,
                        'is_reconciled' => false,
                    ]);
            }

            return;
        }

        $movement = $entry->accountMovements()
            ->whereNull('transaction_installment_id')
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
            'transaction_installment_id' => null,
            'financial_account_id' => $entry->financial_account_id,
            'occurred_on' => $entry->settled_on,
            'description' => $entry->description,
            'amount' => $isExpense ? '-'.$entry->amount : $entry->amount,
            'type' => $isExpense
                ? AccountMovementType::ExpensePayment
                : AccountMovementType::IncomeReceipt,
        ];

        $movement instanceof AccountMovement
            ? $movement->update($data)
            : $entry->accountMovements()->create([
                ...$data,
                'is_reconciled' => false,
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function splitAmount(string $amount, int $count): array
    {
        $totalCents = $this->moneyToCents($amount);

        if ($totalCents < $count) {
            throw ValidationException::withMessages([
                'installment_count' => 'A quantidade de parcelas não pode gerar parcelas com valor zero.',
            ]);
        }

        $base = intdiv($totalCents, $count);
        $remainder = $totalCents % $count;
        $amounts = [];

        for ($index = 0; $index < $count; $index++) {
            $amounts[] = $this->centsToMoney(
                $base + ($index < $remainder ? 1 : 0),
            );
        }

        return $amounts;
    }

    private function dateInMonth(CarbonImmutable $month, int $day): CarbonImmutable
    {
        $base = $month->startOfMonth();

        return $base->addDays(min($day, $base->daysInMonth) - 1);
    }

    private function moneyToCents(string $amount): int
    {
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');

        return ((int) $whole * 100) + (int) $decimal;
    }

    private function centsToMoney(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
