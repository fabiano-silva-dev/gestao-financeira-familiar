<?php

namespace App\Models;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property FinancialImportType $type
 * @property FinancialImportStatus $status
 * @property int $total_records
 * @property int $imported_records
 * @property int $duplicate_records
 * @property Carbon|null $statement_start_on
 * @property Carbon|null $statement_end_on
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $imported_at
 * @property-read FinancialAccount|null $financialAccount
 */
#[Fillable([
    'workspace_id',
    'financial_account_id',
    'credit_card_id',
    'created_by',
    'type',
    'status',
    'source_filename',
    'stored_path',
    'file_hash',
    'deduplication_key',
    'total_records',
    'imported_records',
    'duplicate_records',
    'statement_start_on',
    'statement_end_on',
    'external_account_identifier',
    'metadata',
    'error_message',
    'imported_at',
])]
class FinancialImport extends Model
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<BankStatementEntry, $this> */
    public function bankStatementEntries(): HasMany
    {
        return $this->hasMany(BankStatementEntry::class);
    }

    /** @return HasMany<CardStatementEntry, $this> */
    public function cardStatementEntries(): HasMany
    {
        return $this->hasMany(CardStatementEntry::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => FinancialImportType::class,
            'status' => FinancialImportStatus::class,
            'total_records' => 'integer',
            'imported_records' => 'integer',
            'duplicate_records' => 'integer',
            'statement_start_on' => 'date',
            'statement_end_on' => 'date',
            'metadata' => 'array',
            'imported_at' => 'datetime',
        ];
    }
}
