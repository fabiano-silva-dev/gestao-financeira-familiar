<?php

namespace App\Services\Finance;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Reconciliation\CardStatementReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CardInstallmentPlanService
{
    public function __construct(
        private readonly CardStatementReconciliationService $reconciliationService,
    ) {}

    public function linkExisting(
        Workspace $workspace,
        CreditCard $card,
        CreditCardInvoice $invoice,
        CardStatementEntry $entry,
        User $user,
    ): bool {
        if ($entry->installment_number === null || ($entry->total_installments ?? 1) <= 1) {
            return false;
        }

        $plan = $this->findPlan($card, $entry);

        if (! $plan instanceof FinancialTransaction) {
            return false;
        }

        return DB::transaction(function () use ($workspace, $invoice, $entry, $user, $plan): bool {
            $lockedPlan = FinancialTransaction::query()
                ->whereKey($plan->id)
                ->lockForUpdate()
                ->firstOrFail();
            $installment = $lockedPlan->installments()
                ->where('installment_number', $entry->installment_number)
                ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
                ->lockForUpdate()
                ->first();

            if ($installment instanceof TransactionInstallment) {
                if ($installment->cardStatementEntry()->exists()) {
                    return false;
                }

                $difference = abs(
                    $this->moneyToCents($installment->amount) - $this->moneyToCents($entry->amount),
                );

                if ($difference > 1) {
                    return false;
                }

                $previousInvoiceId = $installment->credit_card_invoice_id;

                if ($previousInvoiceId !== $invoice->id) {
                    $installment->update([
                        'credit_card_invoice_id' => $invoice->id,
                        'due_date' => $invoice->due_date->toDateString(),
                        'expected_payment_date' => $invoice->due_date->toDateString(),
                    ]);
                }

                $installment->update(['amount' => $entry->amount]);
                $this->refreshTransactionTotal($lockedPlan);

                if ($previousInvoiceId !== null && $previousInvoiceId !== $invoice->id) {
                    $previousInvoice = CreditCardInvoice::query()->find($previousInvoiceId);

                    if ($previousInvoice instanceof CreditCardInvoice) {
                        $this->syncInvoice($previousInvoice);
                    }
                }

                $this->syncInvoice($invoice);
            } else {
                $purchaseMonth = CarbonImmutable::parse(
                    $lockedPlan->transaction_date,
                )->startOfMonth();
                $installment = $lockedPlan->installments()->create([
                    'workspace_id' => $lockedPlan->workspace_id,
                    'credit_card_invoice_id' => $invoice->id,
                    'installment_number' => $entry->installment_number,
                    'total_installments' => $entry->total_installments,
                    'amount' => $entry->amount,
                    'competence_month' => $purchaseMonth
                        ->addMonths($entry->installment_number - 1)
                        ->toDateString(),
                    'due_date' => $invoice->due_date->toDateString(),
                    'expected_payment_date' => $invoice->due_date->toDateString(),
                    'status' => TransactionInstallmentStatus::Open,
                ]);
                $this->refreshTransactionTotal($lockedPlan);
                $this->syncInvoice($invoice);
            }

            $this->reconciliationService->reconcile(
                $workspace,
                $invoice,
                $entry,
                $installment->refresh(),
                $user,
            );

            return true;
        });
    }

    /**
     * @return array{
     *     canonical_id: int,
     *     is_canonical: bool,
     *     duplicates: array<int, array{id: int, description: string, amount: string}>
     * }|null
     */
    public function summary(FinancialTransaction $transaction): ?array
    {
        $group = $this->group($transaction);

        if ($group->count() < 2) {
            return null;
        }

        $canonical = $this->canonical($group);

        return [
            'canonical_id' => $canonical->id,
            'is_canonical' => $canonical->id === $transaction->id,
            'duplicates' => $group
                ->reject(fn (FinancialTransaction $item): bool => $item->id === $canonical->id)
                ->map(fn (FinancialTransaction $item): array => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'amount' => (string) $item->amount,
                ])
                ->values()
                ->all(),
        ];
    }

    public function merge(
        Workspace $workspace,
        FinancialTransaction $transaction,
    ): FinancialTransaction {
        return DB::transaction(function () use ($workspace, $transaction): FinancialTransaction {
            $locked = FinancialTransaction::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();
            $group = $this->group($locked);

            if ($group->count() < 2) {
                throw ValidationException::withMessages([
                    'entry' => 'Não há outra compra parcelada igual para unir.',
                ]);
            }

            $canonical = FinancialTransaction::query()
                ->whereKey($this->canonical($group)->id)
                ->lockForUpdate()
                ->firstOrFail();
            $invoiceIds = [];

            foreach ($group as $item) {
                if ($item->id === $canonical->id) {
                    continue;
                }

                $fragment = FinancialTransaction::query()
                    ->whereKey($item->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $invoiceIds = [
                    ...$invoiceIds,
                    ...$this->absorb($canonical, $fragment),
                ];
            }

            $this->refreshTransactionTotal($canonical);

            foreach (array_unique($invoiceIds) as $invoiceId) {
                $invoice = CreditCardInvoice::query()->find($invoiceId);

                if ($invoice instanceof CreditCardInvoice) {
                    $this->syncInvoice($invoice);
                }
            }

            return $canonical->refresh();
        });
    }

    public function syncInvoice(CreditCardInvoice $invoice): void
    {
        $amount = (string) $invoice->installments()
            ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
            ->sum('amount');

        $invoice->update([
            'calculated_amount' => $this->centsToMoney($this->moneyToCents($amount)),
        ]);
    }

    private function findPlan(
        CreditCard $card,
        CardStatementEntry $entry,
    ): ?FinancialTransaction {
        $merchant = $this->merchantKey($entry->description);
        $total = (int) $entry->total_installments;

        if ($merchant === '' || $total <= 1) {
            return null;
        }

        $plans = FinancialTransaction::query()
            ->where('workspace_id', $card->workspace_id)
            ->where('credit_card_id', $card->id)
            ->where('type', FinancialTransactionType::Expense->value)
            ->where('status', FinancialTransactionStatus::Confirmed->value)
            ->whereHas(
                'installments',
                fn ($query) => $query
                    ->where('total_installments', $total)
                    ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value),
            )
            ->with('installments')
            ->orderBy('id')
            ->get()
            ->filter(fn (FinancialTransaction $plan): bool => $this->merchantKey($plan->description) === $merchant
                && $this->amountMatchesPlan($plan, (string) $entry->amount))
            ->values();

        return $this->canonical($plans);
    }

    /**
     * @return Collection<int, FinancialTransaction>
     */
    private function group(FinancialTransaction $transaction): Collection
    {
        $total = $transaction->installments()
            ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
            ->value('total_installments');
        $merchant = $this->merchantKey($transaction->description);

        if (
            $transaction->credit_card_id === null
            || $transaction->type !== FinancialTransactionType::Expense
            || ! is_numeric($total)
            || (int) $total <= 1
            || $merchant === ''
        ) {
            return collect();
        }

        $reference = $this->representativeCents($transaction);

        return FinancialTransaction::query()
            ->where('workspace_id', $transaction->workspace_id)
            ->where('credit_card_id', $transaction->credit_card_id)
            ->where('type', FinancialTransactionType::Expense->value)
            ->where('status', FinancialTransactionStatus::Confirmed->value)
            ->whereHas(
                'installments',
                fn ($query) => $query
                    ->where('total_installments', (int) $total)
                    ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value),
            )
            ->with(['installments' => fn ($query) => $query->orderBy('installment_number')])
            ->orderBy('id')
            ->get()
            ->filter(function (FinancialTransaction $plan) use ($merchant, $reference): bool {
                if ($this->merchantKey($plan->description) !== $merchant || $reference === null) {
                    return false;
                }

                $planCents = $this->representativeCents($plan);

                return $planCents !== null && abs($planCents - $reference) <= 1;
            })
            ->values();
    }

    /**
     * @param  Collection<int, FinancialTransaction>  $plans
     */
    private function canonical(Collection $plans): ?FinancialTransaction
    {
        if ($plans->isEmpty()) {
            return null;
        }

        return $plans
            ->sort(function (FinancialTransaction $left, FinancialTransaction $right): int {
                return [$this->firstInstallmentNumber($left), $left->id]
                    <=> [$this->firstInstallmentNumber($right), $right->id];
            })
            ->first();
    }

    private function firstInstallmentNumber(FinancialTransaction $plan): int
    {
        $number = $plan->installments
            ->filter(fn (TransactionInstallment $installment): bool => $installment->status !== TransactionInstallmentStatus::Cancelled)
            ->min('installment_number');

        return is_numeric($number) ? (int) $number : PHP_INT_MAX;
    }

    private function representativeCents(FinancialTransaction $plan): ?int
    {
        $installment = $plan->installments
            ->first(fn (TransactionInstallment $installment): bool => $installment->status !== TransactionInstallmentStatus::Cancelled);

        return $installment instanceof TransactionInstallment
            ? $this->moneyToCents((string) $installment->amount)
            : null;
    }

    private function amountMatchesPlan(FinancialTransaction $plan, string $amount): bool
    {
        $target = $this->moneyToCents($amount);

        return $plan->installments->contains(
            fn (TransactionInstallment $installment): bool => $installment->status !== TransactionInstallmentStatus::Cancelled
                && abs($this->moneyToCents((string) $installment->amount) - $target) <= 1,
        );
    }

    /**
     * @return array<int, int>
     */
    private function absorb(
        FinancialTransaction $canonical,
        FinancialTransaction $fragment,
    ): array {
        if ($fragment->refunds()->exists() || $fragment->accountMovements()->exists()) {
            throw ValidationException::withMessages([
                'entry' => 'A compra repetida tem reembolso ou movimento de conta e não pode ser unida automaticamente.',
            ]);
        }

        $invoiceIds = [];
        $canonical->load(['installments.cardStatementEntry']);

        foreach ($fragment->installments()->with('cardStatementEntry')->orderBy('installment_number')->get() as $fragmentInstallment) {
            $invoiceIds[] = (int) $fragmentInstallment->credit_card_invoice_id;
            $canonicalInstallment = $canonical->installments
                ->first(fn (TransactionInstallment $installment): bool => $installment->installment_number === $fragmentInstallment->installment_number
                    && $installment->status !== TransactionInstallmentStatus::Cancelled);
            $statement = $fragmentInstallment->cardStatementEntry;

            if (! $canonicalInstallment instanceof TransactionInstallment) {
                $fragmentInstallment->financial_transaction_id = $canonical->id;
                $fragmentInstallment->save();
                $canonical->setRelation(
                    'installments',
                    $canonical->installments->push($fragmentInstallment),
                );

                continue;
            }

            $invoiceIds[] = (int) $canonicalInstallment->credit_card_invoice_id;

            if ($statement instanceof CardStatementEntry) {
                if ($canonicalInstallment->cardStatementEntry instanceof CardStatementEntry) {
                    throw ValidationException::withMessages([
                        'entry' => 'As duas compras já têm a mesma parcela conciliada com linhas diferentes da fatura.',
                    ]);
                }

                $canonicalInstallment->update(['amount' => $statement->amount]);
                $statement->update([
                    'transaction_installment_id' => $canonicalInstallment->id,
                ]);
                $fragmentInstallment->unsetRelation('cardStatementEntry');
            }

            $fragmentInstallment->delete();
        }

        $fragment->delete();

        return $invoiceIds;
    }

    private function refreshTransactionTotal(FinancialTransaction $transaction): void
    {
        $cents = 0;

        foreach (
            $transaction->installments()
                ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
                ->pluck('amount') as $amount
        ) {
            $cents += $this->moneyToCents((string) $amount);
        }

        $notes = $transaction->notes;
        $updates = ['amount' => $this->centsToMoney($cents)];

        if (is_string($notes) && str_contains($notes, 'Valor total estimado')) {
            $replaced = preg_replace(
                '/Valor total estimado a partir de \d+ parcelas de [\d.]+/',
                'Valor atualizado pelas parcelas conciliadas nas faturas',
                $notes,
            );
            $updates['notes'] = is_string($replaced) ? $replaced : $notes;
        }

        $transaction->update($updates);
    }

    private function merchantKey(string $description): string
    {
        $withoutInstallment = preg_replace(
            '/\s*[-–—]?\s*parcela\s+\d+\s*(?:de|\/)\s*\d+\s*$/iu',
            '',
            $description,
        ) ?? $description;
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $withoutInstallment);
        $value = is_string($transliterated) ? $transliterated : $withoutInstallment;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) >= 4 ? $value : '';
    }

    private function moneyToCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');

        $cents = ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
