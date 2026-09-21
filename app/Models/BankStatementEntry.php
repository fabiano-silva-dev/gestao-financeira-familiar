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
 * @property-read FinancialAccount $financialAccount
 * @property-read FinancialImport $financialImport
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'amount' => 'decimal:2',
            'is_reconciled' => 'boolean',
        ];
    }
}
