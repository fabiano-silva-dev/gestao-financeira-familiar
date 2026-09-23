<?php

namespace App\Models;

use App\Enums\ClassificationRuleMatchType;
use App\Enums\FinancialTransactionType;
use Database\Factories\ClassificationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ClassificationRuleMatchType $match_type
 * @property FinancialTransactionType $action_type
 * @property-read Category|null $category
 * @property-read FinancialAccount|null $financialAccount
 * @property-read FinancialAccount|null $counterpartAccount
 */
#[Fillable([
    'name',
    'match_type',
    'pattern',
    'action_type',
    'payee_name',
    'category_id',
    'financial_account_id',
    'counterpart_account_id',
    'is_active',
])]
class ClassificationRule extends Model
{
    /** @use HasFactory<ClassificationRuleFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function counterpartAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'counterpart_account_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_type' => ClassificationRuleMatchType::class,
            'action_type' => FinancialTransactionType::class,
            'is_active' => 'boolean',
        ];
    }
}
