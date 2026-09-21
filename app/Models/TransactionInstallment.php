<?php

namespace App\Models;

use App\Enums\TransactionInstallmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property int $installment_number
 * @property int $total_installments
 * @property string $amount
 * @property Carbon $competence_month
 * @property Carbon $due_date
 * @property Carbon|null $expected_payment_date
 * @property Carbon|null $paid_at
 * @property TransactionInstallmentStatus $status
 * @property-read CreditCardInvoice|null $invoice
 * @property-read FinancialTransaction $transaction
 * @property-read CardStatementEntry|null $cardStatementEntry
 */
#[Fillable([
    'workspace_id',
    'credit_card_invoice_id',
    'installment_number',
    'total_installments',
    'amount',
    'competence_month',
    'due_date',
    'expected_payment_date',
    'paid_at',
    'status',
])]
class TransactionInstallment extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<FinancialTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    /** @return BelongsTo<CreditCardInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class, 'credit_card_invoice_id');
    }

    /** @return HasOne<CardStatementEntry, $this> */
    public function cardStatementEntry(): HasOne
    {
        return $this->hasOne(CardStatementEntry::class);
    }

    protected static function booted(): void
    {
        static::updating(function (TransactionInstallment $installment): void {
            if (
                $installment->isDirty([
                    'credit_card_invoice_id',
                    'installment_number',
                    'total_installments',
                    'amount',
                    'competence_month',
                    'due_date',
                ])
                && $installment->cardStatementEntry()->exists()
            ) {
                throw ValidationException::withMessages([
                    'reconciliation' => 'Desfaça a conciliação da fatura antes de alterar a parcela.',
                ]);
            }
        });

        static::deleting(function (TransactionInstallment $installment): void {
            if ($installment->cardStatementEntry()->exists()) {
                throw ValidationException::withMessages([
                    'reconciliation' => 'Desfaça a conciliação da fatura antes de remover a parcela.',
                ]);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'installment_number' => 'integer',
            'total_installments' => 'integer',
            'amount' => 'decimal:2',
            'competence_month' => 'date',
            'due_date' => 'date',
            'expected_payment_date' => 'date',
            'paid_at' => 'date',
            'status' => TransactionInstallmentStatus::class,
        ];
    }
}
