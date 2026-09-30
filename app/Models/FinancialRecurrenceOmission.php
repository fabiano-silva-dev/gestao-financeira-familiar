<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $occurrence_date
 */
#[Fillable([
    'workspace_id',
    'financial_recurrence_id',
    'occurrence_date',
])]
class FinancialRecurrenceOmission extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<FinancialRecurrence, $this> */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(FinancialRecurrence::class, 'financial_recurrence_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date',
        ];
    }
}
