<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancialTransactionCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $workspace = $currentWorkspace->get();
        abort_if($workspace === null, 403);

        $isBulk = $this->routeIs('transactions.bulk-update-category');

        return [
            'entry_ids' => [
                Rule::requiredIf($isBulk),
                Rule::prohibitedIf(! $isBulk),
                'array',
                'min:1',
            ],
            'entry_ids.*' => ['integer', 'distinct'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists(Category::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'entry_ids' => 'lançamentos',
            'entry_ids.*' => 'lançamento',
            'category_id' => 'categoria',
        ];
    }
}
