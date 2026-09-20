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
 * @property Carbon $transaction_date
 * @property Carbon|null $competence_date
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
 * @property Carbon|null $due_date
 * @property Carbon|null $settled_on
 * @property int|null $parent_transaction_id
 * @property int|null $installment_number
 * @property int|null $installment_count
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
    'parent_transaction_id',
    'installment_number',
    'installment_count',
    'status',
    'origin',
    'notes',
])]
class FinancialTransaction extends Model
{
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'source_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_account_id');
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

    public function parentTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transaction_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(self::class, 'parent_transaction_id')
            ->orderBy('installment_number');
    }

    public function accountMovements(): HasMany
    {
        return $this->hasMany(AccountMovement::class);
    }

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
            'installment_number' => 'integer',
            'installment_count' => 'integer',
            'status' => FinancialTransactionStatus::class,
            'origin' => FinancialTransactionOrigin::class,
        ];
    }
}
