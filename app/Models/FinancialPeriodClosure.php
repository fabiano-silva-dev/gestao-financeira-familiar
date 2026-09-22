<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $workspace_id
 * @property int|null $financial_account_id
 * @property int|null $credit_card_id
 * @property Carbon $reference_month
 * @property string $status
 * @property int|null $closed_by
 * @property Carbon $closed_at
 * @property-read User|null $closedBy
 */
#[Fillable([
    'workspace_id',
    'financial_account_id',
    'credit_card_id',
    'reference_month',
    'status',
    'closed_by',
    'closed_at',
])]
class FinancialPeriodClosure extends Model
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'closed_at' => 'datetime',
        ];
    }
}
