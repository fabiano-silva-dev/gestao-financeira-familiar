<?php

namespace App\Http\Requests;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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

        $type = CategoryType::tryFrom((string) $this->input('type'));

        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(CategoryType::class)],
            'parent_id' => [
                'nullable',
                'integer',
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
