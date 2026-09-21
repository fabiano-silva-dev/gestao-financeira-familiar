<?php

namespace App\Models;

use App\Enums\FinancialAccountType;
use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property FinancialAccountType $type
 * @property string $opening_balance
 * @property Carbon|null $opening_balance_date
 * @property bool $is_active
 */
#[Fillable([
    'name',
    'institution',
    'type',
    'opening_balance',
    'opening_balance_date',
    'is_active',
])]
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<AccountMovement, $this>
     */
    public function movements(): HasMany
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinancialAccountType::class,
            'opening_balance' => 'decimal:2',
            'opening_balance_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
