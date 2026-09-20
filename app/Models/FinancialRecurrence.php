<?php

namespace App\Models;

use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\RecurrenceFrequency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'type',
    'description',
    'amount',
    'financial_account_id',
    'credit_card_id',
    'category_id',
    'family_member_id',
    'payment_method',
    'payee_name',
    'payment_instructions',
    'frequency',
    'interval',
    'starts_on',
    'ends_on',
    'is_active',
    'notes',
])]
class FinancialRecurrence extends Model
{
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    protected function casts(): array
    {
        return [
            'type' => FinancialTransactionType::class,
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'frequency' => RecurrenceFrequency::class,
            'interval' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
