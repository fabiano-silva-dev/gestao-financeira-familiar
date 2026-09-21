<?php

namespace App\Http\Controllers;

use App\Enums\CategoryType;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function index(): Response
    {
        $categories = $this->workspace()
            ->categories()
            ->whereNull('parent_id')
            ->with(['children' => fn ($query) => $query
                ->orderByDesc('is_active')
                ->orderBy('name')])
            ->orderBy('type')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => $this->categoryData($category));

        return Inertia::render('categories/index', [
            'categories' => $categories,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('categories/create', [
            'parentOptions' => $this->parentOptions(),
            'typeOptions' => CategoryType::options(),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $this->workspace()->categories()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Categoria cadastrada com sucesso.',
        ]);

        return to_route('categories.index');
    }

    public function edit(int $category): Response
    {
        $financialCategory = $this->findCategory($category);

        return Inertia::render('categories/edit', [
            'category' => $this->categoryData($financialCategory),
            'parentOptions' => $this->parentOptions($financialCategory->id),
            'typeOptions' => CategoryType::options(),
        ]);
    }

    public function update(
        UpdateCategoryRequest $request,
        int $category,
    ): RedirectResponse {
        $this->findCategory($category)->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Categoria atualizada com sucesso.',
        ]);

        return to_route('categories.index');
    }

    public function toggleStatus(int $category): RedirectResponse
    {
        $financialCategory = $this->findCategory($category);
        $financialCategory->update([
            'is_active' => ! $financialCategory->is_active,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $financialCategory->is_active
                ? 'Categoria ativada com sucesso.'
                : 'Categoria desativada com sucesso.',
        ]);

        return to_route('categories.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findCategory(int $category): Category
    {
        return $this->workspace()
            ->categories()
            ->withCount('children')
            ->findOrFail($category);
    }

    /**
     * @return array<int, array{id: int, name: string, type: string, type_label: string, is_active: bool}>
     */
    private function parentOptions(?int $except = null): array
    {
        return $this->workspace()
            ->categories()
            ->whereNull('parent_id')
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->orderBy('type')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'type' => $category->type->value,
                'type_label' => $category->type->label(),
                'is_active' => $category->is_active,
            ])
            ->all();
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     type: string,
     *     type_label: string,
     *     parent_id: int|null,
     *     is_active: bool,
     *     has_children: bool,
     *     children: array<int, array<string, mixed>>
     * }
     */
    private function categoryData(Category $category): array
    {
        $children = $category->relationLoaded('children')
            ? $category->children
                ->map(fn (Category $child): array => $this->categoryData($child))
                ->all()
            : [];

        return [
            'id' => $category->id,
            'name' => $category->name,
            'type' => $category->type->value,
            'type_label' => $category->type->label(),
            'parent_id' => $category->parent_id,
            'is_active' => $category->is_active,
            'has_children' => $category->getAttribute('children_count') !== null
                ? $category->children_count > 0
                : count($children) > 0,
            'children' => $children,
        ];
    }
}
