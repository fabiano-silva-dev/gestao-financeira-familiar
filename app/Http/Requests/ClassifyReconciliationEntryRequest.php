<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassifyReconciliationEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('category_id') === '' || $this->input('category_id') === 'none') {
            $this->merge(['category_id' => null]);
        }

        if ($this->input('payee_name') === '') {
            $this->merge(['payee_name' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $workspace = $currentWorkspace->get();
        abort_if($workspace === null, 403);

        return [
            'payee_name' => ['nullable', 'string', 'max:160'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists(Category::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)
                        ->where('is_active', true)),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'payee_name' => 'empresa ou beneficiário',
            'category_id' => 'categoria',
        ];
    }
}
