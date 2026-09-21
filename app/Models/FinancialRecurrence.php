<?php

namespace App\Models;

use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\RecurrenceFrequency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property FinancialTransactionType $type
 * @property string $amount
 * @property PaymentMethod $payment_method
 * @property RecurrenceFrequency $frequency
 * @property int $interval
 * @property Carbon $starts_on
 * @property Carbon $generation_started_on
 * @property Carbon|null $ends_on
 * @property bool $is_active
 * @property-read Workspace $workspace
 */
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
    'generation_started_on',
    'ends_on',
    'is_active',
    'notes',
])]
class FinancialRecurrence extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<FamilyMember, $this> */
    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class);
    }

    /** @return HasMany<FinancialTransaction, $this> */
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
            'generation_started_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
