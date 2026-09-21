<?php

namespace App\Http\Requests;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\FinancialRecurrence;
use App\Models\FinancialTransaction;
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
        $category = Category::query()
            ->where('workspace_id', $workspace->id)
            ->find($categoryId);
        $type = CategoryType::tryFrom((string) $this->input('type'));

        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => [
                'required',
                Rule::enum(CategoryType::class),
                function (string $attribute, mixed $value, Closure $fail) use ($category, $workspace): void {
                    if ($category === null || $category->type->value === $value) {
                        return;
                    }

                    $hasChildren = Category::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('parent_id', $category->id)
                        ->exists();

                    $isInUse = FinancialTransaction::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('category_id', $category->id)
                        ->exists()
                        || FinancialRecurrence::query()
                            ->where('workspace_id', $workspace->id)
                            ->where('category_id', $category->id)
                            ->exists();

                    if ($hasChildren || $isInUse) {
                        $fail('O tipo de uma categoria em uso ou com subcategorias não pode ser alterado.');
                    }
                },
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::notIn([$categoryId]),
                Rule::exists(Category::class, 'id')
                    ->where(function (Builder $query) use ($workspace, $type): Builder {
                        $query
                            ->where('workspace_id', $workspace->id)
                            ->whereNull('parent_id');

                        if ($type !== null) {
                            $query->where('type', $type->value);
                        }

                        return $query;
                    }),
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

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'type' => 'tipo',
            'parent_id' => 'categoria principal',
        ];
    }
}
