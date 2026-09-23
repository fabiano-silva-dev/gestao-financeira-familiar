<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $purchased_on
 * @property string $amount
 * @property int|null $installment_number
 * @property int|null $total_installments
 * @property array<string, string|null>|null $raw_data
 * @property bool $is_reconciled
 * @property bool $is_ignored
 * @property Carbon|null $ignored_at
 * @property string|null $suggested_payee_name
 * @property string|null $automation_level_applied
 * @property string|null $automation_result
 * @property int|null $automation_score
 * @property string|null $automation_related_type
 * @property int|null $automation_related_id
 * @property Carbon|null $automation_processed_at
 * @property Carbon|null $reconciled_at
 * @property-read CreditCard $creditCard
 * @property-read CreditCardInvoice $invoice
 * @property-read TransactionInstallment|null $transactionInstallment
 * @property-read Category|null $suggestedCategory
 * @property-read User|null $reconciler
 */
#[Fillable([
    'workspace_id',
    'financial_import_id',
    'credit_card_id',
    'credit_card_invoice_id',
    'purchased_on',
    'description',
    'amount',
    'installment_number',
    'total_installments',
    'external_id',
    'deduplication_key',
    'raw_data',
    'transaction_installment_id',
    'reconciled_by',
    'reconciled_at',
    'is_reconciled',
    'is_ignored',
    'ignored_by',
    'ignored_at',
    'suggested_payee_name',
    'suggested_category_id',
    'matched_classification_rule_id',
    'automation_level_applied',
    'automation_result',
    'automation_score',
    'automation_related_type',
    'automation_related_id',
    'automation_reason',
    'automation_processed_at',
])]
class CardStatementEntry extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<FinancialImport, $this> */
    public function financialImport(): BelongsTo
    {
        return $this->belongsTo(FinancialImport::class);
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

    /** @return BelongsTo<TransactionInstallment, $this> */
    public function transactionInstallment(): BelongsTo
    {
        return $this->belongsTo(TransactionInstallment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    /** @return BelongsTo<User, $this> */
    public function ignoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by');
    }

    /** @return BelongsTo<Category, $this> */
    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'suggested_category_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchased_on' => 'date',
            'amount' => 'decimal:2',
            'installment_number' => 'integer',
            'total_installments' => 'integer',
            'raw_data' => 'array',
            'reconciled_at' => 'datetime',
            'is_reconciled' => 'boolean',
            'is_ignored' => 'boolean',
            'ignored_at' => 'datetime',
            'automation_score' => 'integer',
            'automation_related_id' => 'integer',
            'automation_processed_at' => 'datetime',
        ];
    }
}
