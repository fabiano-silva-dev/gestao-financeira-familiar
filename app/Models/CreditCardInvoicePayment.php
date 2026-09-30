<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $paid_on
 * @property string $amount
 * @property int $credit_card_id
 * @property int|null $credit_card_invoice_id
 * @property PaymentMethod $payment_method
 * @property-read CreditCard $creditCard
 * @property-read CreditCardInvoice|null $invoice
 * @property-read FinancialAccount $account
 */
#[Fillable([
    'workspace_id',
    'credit_card_id',
    'credit_card_invoice_id',
    'financial_account_id',
    'paid_on',
    'amount',
    'is_advance',
    'payment_method',
    'notes',
])]
class CreditCardInvoicePayment extends Model
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

    /** @return BelongsTo<CreditCardInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class, 'credit_card_invoice_id');
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    /** @return HasOne<AccountMovement, $this> */
    public function movement(): HasOne
    {
        return $this->hasOne(AccountMovement::class);
    }

    /** @return HasMany<CardStatementEntry, $this> */
    public function statementEntries(): HasMany
    {
        return $this->hasMany(CardStatementEntry::class, 'credit_card_invoice_payment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:2',
            'is_advance' => 'boolean',
            'payment_method' => PaymentMethod::class,
        ];
    }
}
