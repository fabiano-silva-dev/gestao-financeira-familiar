<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\CreditCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $credit_limit
 * @property int $closing_day
 * @property int $due_day
 * @property PaymentMethod $invoice_payment_method
 * @property bool $is_active
 */
#[Fillable([
    'name',
    'institution',
    'last_four',
    'holder_id',
    'credit_limit',
    'closing_day',
    'due_day',
    'payment_account_id',
    'invoice_payment_method',
    'payment_instructions',
    'is_active',
])]
class CreditCard extends Model
{
    /** @use HasFactory<CreditCardFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<FamilyMember, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'holder_id');
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'payment_account_id');
    }

    /**
     * @return HasMany<CreditCardInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(CreditCardInvoice::class);
    }

    /**
     * @return HasMany<CreditCardInvoicePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CreditCardInvoicePayment::class);
    }

    /**
     * @return HasMany<ExpenseRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(ExpenseRefund::class);
    }

    /**
     * @return HasMany<FinancialTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    /**
     * @return HasMany<CardStatementEntry, $this>
     */
    public function statementEntries(): HasMany
    {
        return $this->hasMany(CardStatementEntry::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'closing_day' => 'integer',
            'due_day' => 'integer',
            'invoice_payment_method' => PaymentMethod::class,
            'is_active' => 'boolean',
        ];
    }
}
