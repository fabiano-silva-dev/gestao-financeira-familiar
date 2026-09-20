<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Support\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
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

        $categoryId = (int) $this->route('category');

        return [
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::notIn([$categoryId]),
                Rule::exists(Category::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)
                        ->whereNull('parent_id')),
                function (string $attribute, mixed $value, Closure $fail) use ($categoryId, $workspace): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $hasChildren = Category::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('parent_id', $categoryId)
                        ->exists();

                    if ($hasChildren) {
                        $fail('Uma categoria com subcategorias não pode virar subcategoria.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'parent_id' => 'categoria principal',
        ];
    }
}
