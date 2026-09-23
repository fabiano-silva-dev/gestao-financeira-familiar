<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $occurred_on
 * @property string $amount
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
 * @property-read FinancialAccount $financialAccount
 * @property-read FinancialImport $financialImport
 * @property-read AccountMovement|null $accountMovement
 * @property-read Category|null $suggestedCategory
 */
#[Fillable([
    'workspace_id',
    'financial_import_id',
    'financial_account_id',
    'external_id',
    'deduplication_key',
    'occurred_on',
    'amount',
    'transaction_type',
    'description',
    'memo',
    'account_movement_id',
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
class BankStatementEntry extends Model
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

    /** @return BelongsTo<FinancialAccount, $this> */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /** @return BelongsTo<AccountMovement, $this> */
    public function accountMovement(): BelongsTo
    {
        return $this->belongsTo(AccountMovement::class);
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
            'occurred_on' => 'date',
            'amount' => 'decimal:2',
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
