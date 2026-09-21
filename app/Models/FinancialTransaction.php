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
    'financial_recurrence_id',
    'recurrence_occurrence_date',
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

    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(FinancialRecurrence::class, 'financial_recurrence_id');
    }

    /**
     * @return HasMany<AccountMovement, $this>
     */
    public function accountMovements(): HasMany
    {
        return $this->hasMany(AccountMovement::class);
    }

    /**
     * @return HasMany<TransactionInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(TransactionInstallment::class);
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
            'recurrence_occurrence_date' => 'date',
            'status' => FinancialTransactionStatus::class,
            'origin' => FinancialTransactionOrigin::class,
        ];
    }
}
