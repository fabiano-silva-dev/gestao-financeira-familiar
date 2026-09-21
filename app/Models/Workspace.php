<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<FinancialAccount, $this>
     */
    public function financialAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class);
    }

    /**
     * @return HasMany<FamilyMember, $this>
     */
    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<CreditCard, $this>
     */
    public function creditCards(): HasMany
    {
        return $this->hasMany(CreditCard::class);
    }

    /**
     * @return HasMany<CreditCardInvoice, $this>
     */
    public function creditCardInvoices(): HasMany
    {
        return $this->hasMany(CreditCardInvoice::class);
    }

    /**
     * @return HasMany<FinancialRecurrence, $this>
     */
    public function financialRecurrences(): HasMany
    {
        return $this->hasMany(FinancialRecurrence::class);
    }

    /**
     * @return HasMany<FinancialTransaction, $this>
     */
    public function financialTransactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    /**
     * @return HasMany<TransactionInstallment, $this>
     */
    public function transactionInstallments(): HasMany
    {
        return $this->hasMany(TransactionInstallment::class);
    }

    /**
     * @return HasMany<CreditCardInvoicePayment, $this>
     */
    public function creditCardInvoicePayments(): HasMany
    {
        return $this->hasMany(CreditCardInvoicePayment::class);
    }

    /**
     * @return HasMany<AccountMovement, $this>
     */
    public function accountMovements(): HasMany
    {
        return $this->hasMany(AccountMovement::class);
    }

    /**
     * @return HasMany<FinancialImport, $this>
     */
    public function financialImports(): HasMany
    {
        return $this->hasMany(FinancialImport::class);
    }

    /**
     * @return HasMany<BankStatementEntry, $this>
     */
    public function bankStatementEntries(): HasMany
    {
        return $this->hasMany(BankStatementEntry::class);
    }

    /**
     * @return HasMany<CardStatementEntry, $this>
     */
    public function cardStatementEntries(): HasMany
    {
        return $this->hasMany(CardStatementEntry::class);
    }
}
