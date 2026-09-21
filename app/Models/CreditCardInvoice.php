<?php

namespace App\Models;

use App\Enums\CreditCardInvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $reference_month
 * @property Carbon $closing_date
 * @property Carbon $due_date
 * @property string $calculated_amount
 * @property string|null $statement_amount
 * @property string $paid_amount
 * @property Carbon|null $paid_at
 * @property CreditCardInvoiceStatus $status
 * @property-read CreditCard $creditCard
 */
#[Fillable([
    'workspace_id',
    'credit_card_id',
    'reference_month',
    'closing_date',
    'due_date',
    'calculated_amount',
    'statement_amount',
    'paid_amount',
    'paid_at',
    'status',
])]
class CreditCardInvoice extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return HasMany<TransactionInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(TransactionInstallment::class);
    }

    /** @return HasMany<CreditCardInvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CreditCardInvoicePayment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'closing_date' => 'date',
            'due_date' => 'date',
            'calculated_amount' => 'decimal:2',
            'statement_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'date',
            'status' => CreditCardInvoiceStatus::class,
        ];
    }
}
