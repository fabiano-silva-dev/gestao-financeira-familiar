<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $period_month
 * @property string $status
 * @property Carbon|null $closed_at
 * @property Carbon|null $reopened_at
 */
#[Fillable([
    'workspace_id',
    'financial_account_id',
    'credit_card_id',
    'period_month',
    'status',
    'closed_by',
    'closed_at',
    'reopened_by',
    'reopened_at',
])]
class ImportPeriodClosure extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }
}
