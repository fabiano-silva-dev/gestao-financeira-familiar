<?php

namespace App\Services\Finance;

use App\Enums\AccountMovementType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\ExpenseRefundStatus;
use App\Enums\TransactionInstallmentStatus;
use App\Models\AccountMovement;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardInvoicePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreditCardInvoiceService
{
    public function __construct(
        private readonly CardStatementMaterializationService $cardMaterialization,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createManual(
        CreditCard $card,
        User $user,
        array $data,
    ): CreditCardInvoice {
        $referenceMonth = CarbonImmutable::createFromFormat(
            'Y-m',
            (string) $data['reference_month'],
        )->startOfMonth();
        $dueDate = CarbonImmutable::parse((string) $data['due_date'])->startOfDay();

        if ($dueDate->format('Y-m') !== $referenceMonth->format('Y-m')) {
            throw ValidationException::withMessages([
                'due_date' => 'O vencimento deve pertencer ao mês de referência da fatura.',
            ]);
        }

        $purchases = array_values(array_filter(
            (array) ($data['purchases'] ?? []),
            fn (mixed $purchase): bool => is_array($purchase),
        ));
        $sameMonthClosing = $this->dateInMonth(
            $referenceMonth,
            $card->closing_day,
        );
        $closingDate = $dueDate->greaterThan($sameMonthClosing)
            ? $sameMonthClosing
            : $this->dateInMonth(
                $referenceMonth->subMonth(),
                $card->closing_day,
            );

        return DB::transaction(function () use (
            $card,
            $user,
            $data,
            $purchases,
            $referenceMonth,
            $dueDate,
            $closingDate,
        ): CreditCardInvoice {
            $invoice = CreditCardInvoice::query()
                ->where('workspace_id', $card->workspace_id)
                ->where('credit_card_id', $card->id)
                ->whereDate('reference_month', $referenceMonth->toDateString())
                ->lockForUpdate()
                ->first();

            if (
                $invoice instanceof CreditCardInvoice
                && $invoice->status !== CreditCardInvoiceStatus::Open
            ) {
                throw ValidationException::withMessages([
                    'reference_month' => 'A fatura deste cartão e mês já está fechada ou possui pagamento.',
                ]);
            }

            if (! $invoice instanceof CreditCardInvoice) {
                $invoice = CreditCardInvoice::query()->create([
                    'workspace_id' => $card->workspace_id,
                    'credit_card_id' => $card->id,
                    'reference_month' => $referenceMonth->toDateString(),
                    'closing_date' => $closingDate->toDateString(),
                    'due_date' => $dueDate->toDateString(),
                    'calculated_amount' => '0.00',
                    'statement_amount' => (string) $data['statement_amount'],
                    'paid_amount' => '0.00',
                    'status' => CreditCardInvoiceStatus::Open->value,
                ]);
            } else {
                $invoice->update([
                    'closing_date' => $closingDate->toDateString(),
                    'due_date' => $dueDate->toDateString(),
                    'statement_amount' => (string) $data['statement_amount'],
                ]);
                $invoice->installments()
                    ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
                    ->update([
                        'due_date' => $dueDate->toDateString(),
                        'expected_payment_date' => $dueDate->toDateString(),
                    ]);
            }

            if ($purchases === []) {
                return $this->close(
                    $invoice,
                    (string) $data['statement_amount'],
                );
            }

            foreach ($purchases as $index => $purchase) {
                $deduplicationKey = hash('sha256', implode('|', [
                    'manual_card_statement',
                    (string) $invoice->id,
                    (string) $index,
                    (string) $purchase['purchased_on'],
                    trim((string) $purchase['description']),
                    (string) $purchase['amount'],
                    (string) $purchase['installment_number'],
                    (string) $purchase['total_installments'],
                ]));
                $entry = CardStatementEntry::query()->firstOrCreate(
                    [
                        'workspace_id' => $card->workspace_id,
                        'credit_card_id' => $card->id,
                        'deduplication_key' => $deduplicationKey,
                    ],
                    [
                        'financial_import_id' => null,
                        'credit_card_invoice_id' => $invoice->id,
                        'purchased_on' => $purchase['purchased_on'],
                        'description' => trim((string) $purchase['description']),
                        'amount' => (string) $purchase['amount'],
                        'installment_number' => (int) $purchase['installment_number'],
                        'total_installments' => (int) $purchase['total_installments'],
                        'external_id' => null,
                        'raw_data' => ['source' => 'manual_invoice'],
                        'is_reconciled' => false,
                        'is_ignored' => false,
                        'suggested_payee_name' => isset($purchase['payee_name'])
                            && trim((string) $purchase['payee_name']) !== ''
                                ? trim((string) $purchase['payee_name'])
                                : null,
                        'suggested_category_id' => isset($purchase['category_id'])
                            && $purchase['category_id'] !== null
                            && $purchase['category_id'] !== ''
                                ? (int) $purchase['category_id']
                                : null,
                    ],
                );

                $this->cardMaterialization->materialize(
                    $invoice->workspace()->firstOrFail(),
                    $card,
                    $invoice,
                    $entry,
                    $user,
                    requireClassification: true,
                );
            }

            return $invoice->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $purchase
     */
    public function addPurchase(
        CreditCardInvoice $invoice,
        User $user,
        array $purchase,
    ): CardStatementEntry {
        return DB::transaction(function () use ($invoice, $user, $purchase): CardStatementEntry {
            $locked = CreditCardInvoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== CreditCardInvoiceStatus::Open) {
                throw ValidationException::withMessages([
                    'description' => 'Só é possível lançar compra em uma fatura aberta.',
                ]);
            }

            $description = trim((string) $purchase['description']);

            if ($this->isPaymentDescription($description)) {
                throw ValidationException::withMessages([
                    'description' => 'Pagamento de fatura não é uma nova compra.',
                ]);
            }

            $card = $locked->creditCard()->firstOrFail();
            $categoryId = $purchase['category_id'] ?? null;
            $payeeName = isset($purchase['payee_name'])
                ? trim((string) $purchase['payee_name'])
                : '';
            $entry = CardStatementEntry::query()->create([
                'workspace_id' => $locked->workspace_id,
                'financial_import_id' => null,
                'credit_card_id' => $card->id,
                'credit_card_invoice_id' => $locked->id,
                'purchased_on' => $purchase['purchased_on'],
                'description' => $description,
                'amount' => (string) $purchase['amount'],
                'installment_number' => (int) $purchase['installment_number'],
                'total_installments' => (int) $purchase['total_installments'],
                'external_id' => null,
                'deduplication_key' => hash('sha256', implode('|', [
                    'manual_invoice_purchase',
                    (string) $locked->id,
                    (string) Str::uuid(),
                ])),
                'raw_data' => ['source' => 'manual_invoice'],
                'is_reconciled' => false,
                'is_ignored' => false,
                'suggested_payee_name' => $payeeName !== '' ? $payeeName : null,
                'suggested_category_id' => $categoryId !== null && $categoryId !== ''
                    ? (int) $categoryId
                    : null,
            ]);

            $this->cardMaterialization->materialize(
                $locked->workspace()->firstOrFail(),
                $card,
                $locked,
                $entry,
                $user,
                requireClassification: true,
            );

            return $entry->refresh();
        });
    }

    public function close(CreditCardInvoice $invoice, ?string $statementAmount = null): CreditCardInvoice
    {
        if ($invoice->status === CreditCardInvoiceStatus::Paid) {
            throw ValidationException::withMessages([
                'statement_amount' => 'Uma fatura já paga não pode ser fechada novamente.',
            ]);
        }

        $hasPendingEntries = $invoice->statementEntries()
            ->where('is_reconciled', false)
            ->where('is_ignored', false)
            ->exists();

        if ($hasPendingEntries) {
            throw ValidationException::withMessages([
                'statement_amount' => 'Resolva as compras pendentes antes de fechar a fatura.',
            ]);
        }

        $invoice->update([
            'statement_amount' => $statementAmount,
            'status' => $invoice->status === CreditCardInvoiceStatus::Partial
                ? CreditCardInvoiceStatus::Partial
                : CreditCardInvoiceStatus::Closed,
        ]);

        $this->autoLinkPendingPayments($invoice);
        $this->syncInvoiceSettlement($invoice->refresh());

        return $invoice->refresh();
    }

    public function reopen(CreditCardInvoice $invoice): CreditCardInvoice
    {
        return DB::transaction(function () use ($invoice): CreditCardInvoice {
            $locked = CreditCardInvoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== CreditCardInvoiceStatus::Closed) {
                throw ValidationException::withMessages([
                    'invoice' => 'Só uma fatura fechada, sem pagamento, pode ser reaberta.',
                ]);
            }

            if ($locked->payments()->exists()) {
                throw ValidationException::withMessages([
                    'invoice' => 'Não é possível reabrir uma fatura que já possui pagamento.',
                ]);
            }

            $locked->update([
                'status' => CreditCardInvoiceStatus::Open,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function pay(
        CreditCardInvoice $invoice,
        array $data,
        bool $allowOpen = false,
        bool $allowAdvance = false,
    ): CreditCardInvoice {
        return DB::transaction(function () use ($invoice, $data, $allowOpen, $allowAdvance): CreditCardInvoice {
            $locked = CreditCardInvoice::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === CreditCardInvoiceStatus::Open) {
                if (! $allowOpen) {
                    throw ValidationException::withMessages([
                        'amount' => 'Feche a fatura antes de registrar o pagamento.',
                    ]);
                }
            }

            $paymentCents = $this->moneyToCents((string) $data['amount']);
            $outstandingCents = $this->outstandingCents($locked);

            if (
                $paymentCents <= 0
                || (! $allowAdvance && $paymentCents > $outstandingCents)
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'O pagamento deve ser maior que zero e, quando não for antecipado, não pode ultrapassar o saldo da fatura.',
                ]);
            }

            $payment = $locked->payments()->create([
                'workspace_id' => $locked->workspace_id,
                'credit_card_id' => $locked->credit_card_id,
                'financial_account_id' => $data['financial_account_id'],
                'paid_on' => $data['paid_on'],
                'amount' => $this->centsToMoney($paymentCents),
                'is_advance' => $allowAdvance,
                'payment_method' => $data['payment_method'],
                'notes' => $data['notes'] ?? null,
            ]);

            $payment->movement()->create([
                'workspace_id' => $locked->workspace_id,
                'financial_account_id' => $data['financial_account_id'],
                'occurred_on' => $data['paid_on'],
                'description' => 'Pagamento cartão '.$locked->creditCard()->value('name'),
                'amount' => '-'.$this->centsToMoney($paymentCents),
                'type' => AccountMovementType::CardPayment,
                'is_reconciled' => false,
            ]);

            $this->syncInvoiceSettlement($locked);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePayment(
        CreditCardInvoice $invoice,
        CreditCardInvoicePayment $payment,
        array $data,
    ): CreditCardInvoice {
        return DB::transaction(function () use ($invoice, $payment, $data): CreditCardInvoice {
            $lockedInvoice = CreditCardInvoice::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPayment = CreditCardInvoicePayment::query()
                ->where('workspace_id', $lockedInvoice->workspace_id)
                ->where('credit_card_invoice_id', $lockedInvoice->id)
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $newCents = $this->moneyToCents((string) $data['amount']);
            $currentCents = $this->moneyToCents((string) $lockedPayment->amount);
            $allowedCents = $this->outstandingCents($lockedInvoice) + $currentCents;

            if ($newCents <= 0 || $newCents > $allowedCents) {
                throw ValidationException::withMessages([
                    'amount' => 'O pagamento deve ser maior que zero e não pode ultrapassar o saldo da fatura.',
                ]);
            }

            $amount = $this->centsToMoney($newCents);
            $lockedPayment->update([
                'financial_account_id' => $data['financial_account_id'],
                'paid_on' => $data['paid_on'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'notes' => $data['notes'] ?? null,
            ]);

            $movementData = [
                'financial_account_id' => $data['financial_account_id'],
                'occurred_on' => $data['paid_on'],
                'description' => 'Pagamento cartão '.$lockedInvoice->creditCard()->value('name'),
                'amount' => '-'.$amount,
            ];
            $movement = $lockedPayment->movement()->lockForUpdate()->first();

            if ($movement === null) {
                $lockedPayment->movement()->create([
                    'workspace_id' => $lockedInvoice->workspace_id,
                    ...$movementData,
                    'type' => AccountMovementType::CardPayment,
                    'is_reconciled' => false,
                ]);
            } else {
                $movement->update($movementData);
            }

            $this->syncInvoiceSettlement($lockedInvoice);

            return $lockedInvoice->refresh();
        });
    }

    public function deletePayment(
        CreditCardInvoice $invoice,
        CreditCardInvoicePayment $payment,
    ): CreditCardInvoice {
        return DB::transaction(function () use ($invoice, $payment): CreditCardInvoice {
            $lockedInvoice = CreditCardInvoice::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPayment = CreditCardInvoicePayment::query()
                ->where('workspace_id', $lockedInvoice->workspace_id)
                ->where('credit_card_invoice_id', $lockedInvoice->id)
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $movement = $lockedPayment->movement()->lockForUpdate()->first();
            $movement?->delete();
            $lockedPayment->delete();
            $this->syncInvoiceSettlement($lockedInvoice);

            return $lockedInvoice->refresh();
        });
    }

    /**
     * Registra uma saída destinada ao cartão mesmo quando a fatura ainda
     * não existe ou ainda não foi identificada.
     *
     * @param  array<string, mixed>  $data
     */
    public function createPendingPayment(
        CreditCard $card,
        array $data,
    ): CreditCardInvoicePayment {
        return DB::transaction(function () use ($card, $data): CreditCardInvoicePayment {
            $paymentCents = $this->moneyToCents((string) $data['amount']);

            if ($paymentCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'O pagamento do cartão deve ser maior que zero.',
                ]);
            }

            $payment = CreditCardInvoicePayment::query()->create([
                'workspace_id' => $card->workspace_id,
                'credit_card_id' => $card->id,
                'credit_card_invoice_id' => null,
                'financial_account_id' => $data['financial_account_id'],
                'paid_on' => $data['paid_on'],
                'amount' => $this->centsToMoney($paymentCents),
                'is_advance' => false,
                'payment_method' => $data['payment_method'],
                'notes' => $data['notes'] ?? null,
            ]);

            $payment->movement()->create([
                'workspace_id' => $card->workspace_id,
                'financial_account_id' => $data['financial_account_id'],
                'occurred_on' => $data['paid_on'],
                'description' => 'Pagamento cartão '.$card->name,
                'amount' => '-'.$this->centsToMoney($paymentCents),
                'type' => AccountMovementType::CardPayment,
                'is_reconciled' => false,
            ]);

            return $payment->refresh();
        });
    }

    public function resolveOrCreateOpenInvoice(
        CreditCard $card,
        string $referenceMonth,
    ): CreditCardInvoice {
        $reference = CarbonImmutable::createFromFormat('Y-m', $referenceMonth)
            ->startOfMonth();
        $dueDate = $this->dateInMonth($reference, $card->due_day);
        $closingMonth = $card->due_day > $card->closing_day
            ? $reference
            : $reference->subMonth();
        $closingDate = $this->dateInMonth($closingMonth, $card->closing_day);

        return CreditCardInvoice::query()->firstOrCreate(
            [
                'workspace_id' => $card->workspace_id,
                'credit_card_id' => $card->id,
                'reference_month' => $reference->toDateString(),
            ],
            [
                'closing_date' => $closingDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'calculated_amount' => '0.00',
                'statement_amount' => null,
                'paid_amount' => '0.00',
                'status' => CreditCardInvoiceStatus::Open,
            ],
        );
    }

    public function findCompatibleUnreconciledCardPayment(
        CreditCard $card,
        string $amount,
        string $paidOn,
        ?CreditCardInvoice $invoice = null,
    ): ?CreditCardInvoicePayment {
        $targetCents = $this->moneyToCents($amount);
        $payments = CreditCardInvoicePayment::query()
            ->where('workspace_id', $card->workspace_id)
            ->where('credit_card_id', $card->id)
            ->with(['movement.bankStatementEntry', 'invoice'])
            ->get()
            ->filter(function (CreditCardInvoicePayment $payment) use (
                $targetCents,
                $paidOn,
                $invoice,
            ): bool {
                if ($this->moneyToCents((string) $payment->amount) !== $targetCents) {
                    return false;
                }

                if ($payment->movement?->bankStatementEntry !== null) {
                    return false;
                }

                if (
                    $invoice instanceof CreditCardInvoice
                    && $payment->credit_card_invoice_id !== null
                    && $payment->credit_card_invoice_id !== $invoice->id
                ) {
                    return false;
                }

                return (int) abs($payment->paid_on->diffInDays($paidOn, false)) <= 7;
            })
            ->sortBy(fn (CreditCardInvoicePayment $payment): int => (int) abs(
                $payment->paid_on->diffInDays($paidOn, false),
            ))
            ->values();

        if ($payments->isEmpty()) {
            return null;
        }

        $closestDistance = (int) abs(
            $payments->first()->paid_on->diffInDays($paidOn, false),
        );
        $closest = $payments->filter(
            fn (CreditCardInvoicePayment $payment): bool => (int) abs(
                $payment->paid_on->diffInDays($paidOn, false),
            ) === $closestDistance,
        );

        return $closest->count() === 1 ? $closest->first() : null;
    }

    public function attachBankMovement(
        CreditCardInvoicePayment $payment,
        int $accountId,
        string $paidOn,
        string $amount,
    ): AccountMovement {
        return DB::transaction(function () use (
            $payment,
            $accountId,
            $paidOn,
            $amount,
        ): AccountMovement {
            $locked = CreditCardInvoicePayment::query()
                ->where('workspace_id', $payment->workspace_id)
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $amountCents = $this->moneyToCents($amount);

            if (
                $amountCents <= 0
                || $this->moneyToCents((string) $locked->amount) !== $amountCents
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'O valor bancário não corresponde ao pagamento do cartão.',
                ]);
            }

            $movement = $locked->movement()->lockForUpdate()->first();

            if ($movement instanceof AccountMovement) {
                if (
                    $movement->financial_account_id !== $accountId
                    || $this->moneyToCents((string) $movement->amount) !== -$amountCents
                ) {
                    throw ValidationException::withMessages([
                        'financial_account_id' => 'O pagamento já possui uma movimentação em outra conta ou valor.',
                    ]);
                }

                return $movement;
            }

            $locked->update([
                'financial_account_id' => $accountId,
                'paid_on' => $paidOn,
            ]);

            return $locked->movement()->create([
                'workspace_id' => $locked->workspace_id,
                'financial_account_id' => $accountId,
                'occurred_on' => $paidOn,
                'description' => 'Pagamento cartão '.$locked->creditCard()->value('name'),
                'amount' => '-'.$this->centsToMoney($amountCents),
                'type' => AccountMovementType::CardPayment,
                'is_reconciled' => false,
            ]);
        });
    }

    public function registerImportedPaymentEvidence(
        CreditCard $card,
        CardStatementEntry $entry,
        User $user,
    ): ?CreditCardInvoicePayment {
        return DB::transaction(function () use ($card, $entry, $user): ?CreditCardInvoicePayment {
            $lockedEntry = CardStatementEntry::query()
                ->where('workspace_id', $card->workspace_id)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedEntry->is_payment) {
                return null;
            }

            if ($lockedEntry->credit_card_invoice_payment_id !== null) {
                return $lockedEntry->invoicePayment()->first();
            }

            $targetCents = $this->moneyToCents(ltrim((string) $lockedEntry->amount, '-'));
            $candidates = CreditCardInvoicePayment::query()
                ->where('workspace_id', $card->workspace_id)
                ->where('credit_card_id', $card->id)
                ->whereDoesntHave('statementEntries')
                ->get()
                ->filter(fn (CreditCardInvoicePayment $payment): bool =>
                    $this->moneyToCents((string) $payment->amount) === $targetCents
                    && (int) abs(
                        $payment->paid_on->diffInDays($lockedEntry->purchased_on, false),
                    ) <= 7
                )
                ->sortBy(fn (CreditCardInvoicePayment $payment): int => (int) abs(
                    $payment->paid_on->diffInDays($lockedEntry->purchased_on, false),
                ))
                ->values();

            $payment = null;

            if ($candidates->isNotEmpty()) {
                $closestDistance = (int) abs(
                    $candidates->first()->paid_on->diffInDays($lockedEntry->purchased_on, false),
                );
                $closest = $candidates->filter(
                    fn (CreditCardInvoicePayment $candidate): bool => (int) abs(
                        $candidate->paid_on->diffInDays($lockedEntry->purchased_on, false),
                    ) === $closestDistance,
                );

                if ($closest->count() !== 1) {
                    return null;
                }

                $payment = $closest->first();
            }

            if (! $payment instanceof CreditCardInvoicePayment) {
                $payment = CreditCardInvoicePayment::query()->create([
                    'workspace_id' => $card->workspace_id,
                    'credit_card_id' => $card->id,
                    'credit_card_invoice_id' => null,
                    'financial_account_id' => null,
                    'paid_on' => $lockedEntry->purchased_on->toDateString(),
                    'amount' => $this->centsToMoney($targetCents),
                    'is_advance' => false,
                    'payment_method' => $card->invoice_payment_method->value,
                    'notes' => 'Pagamento identificado na fatura importada; aguardando confirmação no extrato bancário.',
                ]);
            }

            $lockedEntry->update([
                'credit_card_invoice_payment_id' => $payment->id,
                'is_reconciled' => true,
                'reconciled_by' => $user->id,
                'reconciled_at' => now(),
            ]);

            return $payment->refresh();
        });
    }

    public function linkPendingPayment(
        CreditCardInvoice $invoice,
        CreditCardInvoicePayment $payment,
    ): CreditCardInvoice {
        return DB::transaction(function () use ($invoice, $payment): CreditCardInvoice {
            $lockedInvoice = CreditCardInvoice::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPayment = CreditCardInvoicePayment::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->credit_card_invoice_id === $lockedInvoice->id) {
                return $lockedInvoice->refresh();
            }

            if ($lockedPayment->credit_card_invoice_id !== null) {
                throw ValidationException::withMessages([
                    'payment' => 'Este pagamento já está vinculado a outra fatura.',
                ]);
            }

            if ($lockedPayment->credit_card_id !== $lockedInvoice->credit_card_id) {
                throw ValidationException::withMessages([
                    'payment' => 'O pagamento e a fatura devem pertencer ao mesmo cartão.',
                ]);
            }

            $paymentCents = $this->moneyToCents((string) $lockedPayment->amount);
            $outstandingCents = $this->outstandingCents($lockedInvoice);
            $isAdvance = $lockedInvoice->status === CreditCardInvoiceStatus::Open
                && (
                    $lockedInvoice->statement_amount === null
                    || $paymentCents > $outstandingCents
                );

            if (
                $paymentCents <= 0
                || (! $isAdvance && $paymentCents > $outstandingCents)
            ) {
                throw ValidationException::withMessages([
                    'payment' => 'O valor do pagamento não é compatível com o saldo em aberto desta fatura.',
                ]);
            }

            $lockedPayment->update([
                'credit_card_invoice_id' => $lockedInvoice->id,
                'is_advance' => $lockedPayment->is_advance || $isAdvance,
            ]);
            $this->syncInvoiceSettlement($lockedInvoice);

            return $lockedInvoice->refresh();
        });
    }

    public function autoLinkPendingPayments(CreditCardInvoice $invoice): int
    {
        $invoice->refresh();

        if ($invoice->statement_amount === null) {
            return 0;
        }

        $linked = 0;
        $payments = CreditCardInvoicePayment::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('credit_card_id', $invoice->credit_card_id)
            ->whereNull('credit_card_invoice_id')
            ->whereHas('movement')
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();

        foreach ($payments as $payment) {
            $candidates = $this->compatibleImportedInvoices($payment);

            if (
                $candidates->count() !== 1
                || $candidates->first()?->id !== $invoice->id
            ) {
                continue;
            }

            $this->linkPendingPayment($invoice->refresh(), $payment);
            $linked++;
        }

        return $linked;
    }

    /** @return Collection<int, CreditCardInvoice> */
    private function compatibleImportedInvoices(
        CreditCardInvoicePayment $payment,
    ): Collection {
        $paymentCents = $this->moneyToCents((string) $payment->amount);

        return CreditCardInvoice::query()
            ->where('workspace_id', $payment->workspace_id)
            ->where('credit_card_id', $payment->credit_card_id)
            ->whereNotNull('statement_amount')
            ->whereIn('status', [
                CreditCardInvoiceStatus::Open->value,
                CreditCardInvoiceStatus::Closed->value,
                CreditCardInvoiceStatus::Partial->value,
            ])
            ->get()
            ->filter(function (CreditCardInvoice $candidate) use ($payment, $paymentCents): bool {
                $dateDistance = (int) abs(
                    $payment->paid_on->diffInDays($candidate->due_date, false),
                );

                return $dateDistance <= 45
                    && $paymentCents > 0
                    && (
                        $candidate->status === CreditCardInvoiceStatus::Open
                        || $paymentCents <= $this->outstandingCents($candidate)
                    );
            })
            ->values();
    }

    private function syncInvoiceSettlement(CreditCardInvoice $invoice): void
    {
        $payments = $invoice->payments()
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();
        $paidCents = $payments->reduce(
            fn (int $total, CreditCardInvoicePayment $payment): int => $total + $this->moneyToCents((string) $payment->amount),
            0,
        );
        $totalCents = $this->moneyToCents($this->totalAmount($invoice));
        $isPaid = $totalCents > 0 && $paidCents >= $totalCents;
        $latestPaidOn = $payments->last()?->paid_on->toDateString();
        $hasAdvance = $payments->contains(
            fn (CreditCardInvoicePayment $payment): bool => (bool) $payment->is_advance,
        );
        $status = match (true) {
            $invoice->status === CreditCardInvoiceStatus::Open
                && $invoice->statement_amount === null
                && $hasAdvance => CreditCardInvoiceStatus::Open,
            $isPaid => CreditCardInvoiceStatus::Paid,
            $paidCents > 0 => CreditCardInvoiceStatus::Partial,
            $invoice->statement_amount === null => CreditCardInvoiceStatus::Open,
            default => CreditCardInvoiceStatus::Closed,
        };

        $invoice->update([
            'paid_amount' => $this->centsToMoney($paidCents),
            'paid_at' => $isPaid ? $latestPaidOn : null,
            'status' => $status,
        ]);

        if ($isPaid) {
            $invoice->installments()
                ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
                ->update([
                    'status' => TransactionInstallmentStatus::Paid->value,
                    'paid_at' => $latestPaidOn,
                ]);

            return;
        }

        $invoice->installments()
            ->where('status', TransactionInstallmentStatus::Paid->value)
            ->update([
                'status' => TransactionInstallmentStatus::Open->value,
                'paid_at' => null,
            ]);
    }

    public function outstandingAmount(CreditCardInvoice $invoice): string
    {
        return $this->centsToMoney($this->outstandingCents($invoice));
    }

    public function creditBalanceAmount(CreditCardInvoice $invoice): string
    {
        $total = $this->moneyToCents($this->totalAmount($invoice));
        $paid = $this->moneyToCents((string) $invoice->paid_amount);

        return $this->centsToMoney(max(0, $paid - $total));
    }

    public function grossTotalAmount(CreditCardInvoice $invoice): string
    {
        return (string) ($invoice->statement_amount ?? $invoice->calculated_amount);
    }

    public function grossTotalCents(CreditCardInvoice $invoice): int
    {
        return $this->moneyToCents($this->grossTotalAmount($invoice));
    }

    public function totalAmount(CreditCardInvoice $invoice): string
    {
        if ($invoice->statement_amount !== null) {
            return (string) $invoice->statement_amount;
        }

        return $this->centsToMoney(
            max(0, $this->grossTotalCents($invoice) - $this->refundCents($invoice)),
        );
    }

    public function refundCents(CreditCardInvoice $invoice): int
    {
        $refunds = $invoice->refunds()
            ->where('status', ExpenseRefundStatus::Confirmed->value)
            ->pluck('amount')
            ->all();

        return array_reduce(
            $refunds,
            fn (int $total, mixed $amount): int => $total + $this->moneyToCents((string) $amount),
            0,
        );
    }

    public function refundAmount(CreditCardInvoice $invoice): string
    {
        return $this->centsToMoney($this->refundCents($invoice));
    }

    public function statementDifference(CreditCardInvoice $invoice): ?string
    {
        if ($invoice->statement_amount === null) {
            return null;
        }

        return $this->centsToMoney(
            $this->moneyToCents((string) $invoice->statement_amount)
            - $this->moneyToCents((string) $invoice->calculated_amount),
        );
    }

    public function findCompatibleUnreconciledPayment(
        CreditCardInvoice $invoice,
        int $accountId,
        string $amount,
        string $paidOn,
    ): ?CreditCardInvoicePayment {
        $targetCents = $this->moneyToCents($amount);
        $paidOnDate = $paidOn;

        return $invoice->payments()
            ->with('movement')
            ->where('financial_account_id', $accountId)
            ->get()
            ->filter(function (CreditCardInvoicePayment $payment) use ($targetCents): bool {
                $movement = $payment->movement;

                return $movement !== null
                    && ! $movement->is_reconciled
                    && $movement->bankStatementEntry()->doesntExist()
                    && $this->moneyToCents((string) $payment->amount) === $targetCents;
            })
            ->sortBy(fn (CreditCardInvoicePayment $payment): int => (int) abs(
                $payment->paid_on->diffInDays($paidOnDate, false),
            ))
            ->first();
    }

    public function outstandingCents(CreditCardInvoice $invoice): int
    {
        $total = $this->moneyToCents($this->totalAmount($invoice));
        $paid = $this->moneyToCents((string) $invoice->paid_amount);

        return max(0, $total - $paid);
    }

    private function isPaymentDescription(string $description): bool
    {
        $normalized = mb_strtolower($description);

        return str_contains($normalized, 'pagamento recebido')
            || str_contains($normalized, 'pagamento da fatura')
            || str_contains($normalized, 'pagamento de fatura');
    }

    private function dateInMonth(
        CarbonImmutable $month,
        int $day,
    ): CarbonImmutable {
        $base = $month->startOfMonth();
        $safeDay = min($day, $base->daysInMonth);

        return $base->addDays($safeDay - 1);
    }

    private function moneyToCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $decimal;

        return $negative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
