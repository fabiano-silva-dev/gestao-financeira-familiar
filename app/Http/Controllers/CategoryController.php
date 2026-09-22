<?php

namespace App\Http\Controllers;

use App\Enums\CategoryType;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Workspace;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $listing = ListingQuery::from(
            $request,
            ['name', 'type', 'status'],
            'type',
            'asc',
            ['type', 'status'],
        );
        $query = $workspace->categories()->getQuery()->whereNull('parent_id');

        if ($listing->search !== '') {
            $term = $listing->searchTerm();
            $query->where(function ($inner) use ($term): void {
                $inner->where('name', 'ilike', $term)
                    ->orWhereHas(
                        'children',
                        fn ($children) => $children->where('name', 'ilike', $term),
                    );
            });
        }

        $type = $listing->filter('type');

        if ($type !== null && CategoryType::tryFrom($type) !== null) {
            $query->where('type', $type);
        }

        $active = $listing->booleanFilter('status');

        if ($active !== null) {
            $query->where(function ($statusQuery) use ($active): void {
                $statusQuery
                    ->where('is_active', $active)
                    ->orWhereHas(
                        'children',
                        fn ($children) => $children->where('is_active', $active),
                    );
            });
        }

        $orderChildren = function ($children) use ($listing): void {
            if ($listing->sort === 'name') {
                $children->orderBy('name', $listing->direction);
            } elseif ($listing->sort === 'status') {
                $children->orderBy('is_active', $listing->direction)->orderBy('name');
            } else {
                $children->orderByDesc('is_active')->orderBy('name');
            }
        };

        $query->with(['children' => $orderChildren]);

        if ($listing->sort === 'name') {
            $query->orderBy('name', $listing->direction);
        } elseif ($listing->sort === 'status') {
            $query->orderBy('is_active', $listing->direction)->orderBy('name');
        } else {
            $query->orderBy('type', $listing->direction)
                ->orderByDesc('is_active')
                ->orderBy('name');
        }

        $categories = $query
            ->get()
            ->map(function (Category $category) use ($listing): ?array {
                $data = $this->categoryData($category);

                if ($listing->search !== '') {
                    $needle = mb_strtolower($listing->search);
                    $parentMatches = str_contains(mb_strtolower($category->name), $needle);
                    $data['children'] = array_values(array_filter(
                        $data['children'],
                        fn (array $child): bool => $parentMatches
                            || str_contains(mb_strtolower($child['name']), $needle),
                    ));
                }

                $active = $listing->booleanFilter('status');

                if ($active !== null) {
                    $parentMatches = $category->is_active === $active;
                    $data['children'] = array_values(array_filter(
                        $data['children'],
                        fn (array $child): bool => $child['is_active'] === $active,
                    ));

                    if (! $parentMatches && $data['children'] === []) {
                        return null;
                    }
                }

                return $data;
            })
            ->filter()
            ->values();

        return Inertia::render('categories/index', [
            'categories' => $categories,
            'filters' => $listing->toArray(),
            'hasRecords' => $workspace->categories()->whereNull('parent_id')->exists(),
            'typeOptions' => CategoryType::options(),
            'statusOptions' => ListingQuery::statusOptions(),
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
