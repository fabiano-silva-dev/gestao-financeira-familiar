<?php

namespace App\Models;

use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property FinancialTransactionType $type
 * @property \Illuminate\Support\Carbon $transaction_date
 * @property \Illuminate\Support\Carbon|null $competence_date
 * @property string $description
 * @property string $amount
 * @property int|null $financial_account_id
 * @property int|null $source_account_id
 * @property int|null $destination_account_id
 * @property int|null $credit_card_id
 * @property int|null $category_id
 * @property int|null $family_member_id
 * @property PaymentMethod|null $payment_method
 * @property string|null $payee_name
 * @property string|null $payment_instructions
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property \Illuminate\Support\Carbon|null $settled_on
 * @property FinancialTransactionStatus $status
 * @property FinancialTransactionOrigin $origin
 * @property string|null $notes
 */
#[Fillable([
    'type',
    'transaction_date',
    'competence_date',
    'description',
    'amount',
    'financial_account_id',
    'source_account_id',
    'destination_account_id',
    'credit_card_id',
    'category_id',
    'family_member_id',
    'payment_method',
    'payee_name',
    'payment_instructions',
    'due_date',
    'settled_on',
    'status',
    'origin',
    'notes',
])]
class FinancialTransaction extends Model
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'source_account_id');
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_account_id');
    }

    /**
     * @return BelongsTo<CreditCard, $this>
     */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<FamilyMember, $this>
     */
    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class);
    }

    /**
     * @return HasMany<AccountMovement, $this>
     */
    public function accountMovements(): HasMany
    {
        return $this->hasMany(AccountMovement::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinancialTransactionType::class,
            'transaction_date' => 'date',
            'competence_date' => 'date',
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'due_date' => 'date',
            'settled_on' => 'date',
            'status' => FinancialTransactionStatus::class,
            'origin' => FinancialTransactionOrigin::class,
        ];
    }
}
