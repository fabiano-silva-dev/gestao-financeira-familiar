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
 * @property Carbon|null $reconciled_at
 * @property-read FinancialAccount $financialAccount
 * @property-read FinancialImport $financialImport
 * @property-read AccountMovement|null $accountMovement
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'amount' => 'decimal:2',
            'reconciled_at' => 'datetime',
            'is_reconciled' => 'boolean',
        ];
    }
}
