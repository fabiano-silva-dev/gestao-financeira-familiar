<?php

namespace App\Support\Listings;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ListingQuery
{
    /**
     * @param  array<string, string|null>  $filters
     */
    public function __construct(
        public readonly string $search,
        public readonly string $sort,
        public readonly string $direction,
        public readonly array $filters,
    ) {}

    /**
     * @param  list<string>  $allowedSorts
     * @param  list<string>  $filterKeys
     */
    public static function from(
        Request $request,
        array $allowedSorts,
        string $defaultSort,
        string $defaultDirection = 'desc',
        array $filterKeys = [],
    ): self {
        $sort = $request->string('sort')->toString();

        if (! in_array($sort, $allowedSorts, true)) {
            $sort = $defaultSort;
        }

        $direction = strtolower($request->string('direction')->toString());

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        $filters = [];

        foreach ($filterKeys as $key) {
            $value = trim($request->string($key)->toString());
            $filters[$key] = $value === '' || $value === 'all' ? null : $value;
        }

        return new self(
            search: trim($request->string('q')->toString()),
            sort: $sort,
            direction: $direction,
            filters: $filters,
        );
    }

    public function filter(string $key): ?string
    {
        $value = $this->filters[$key] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    public function booleanFilter(string $key): ?bool
    {
        return match ($this->filter($key)) {
            'active' => true,
            'inactive' => false,
            default => null,
        };
    }

    public function intFilter(string $key): ?int
    {
        $value = $this->filter($key);

        if ($value === null || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  Builder<*>|Relation<*, *, *>  $query
     * @param  list<string>  $columns
     */
    public function applySearch(Builder|Relation $query, array $columns): Builder
    {
        $query = $this->eloquentQuery($query);

        if ($this->search === '' || $columns === []) {
            return $query;
        }

        $term = $this->searchTerm();

        return $query->where(function (Builder $inner) use ($columns, $term): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $inner->{$method}($column, 'ilike', $term);
            }
        });
    }

    /**
     * @param  Builder<*>|Relation<*, *, *>  $query
     * @param  array<string, Closure(Builder, string): void|string>  $columns
     */
    public function applySort(Builder|Relation $query, array $columns, ?string $tieBreaker = 'id'): Builder
    {
        $query = $this->eloquentQuery($query);
        $column = $columns[$this->sort] ?? null;

        if ($column instanceof Closure) {
            $column($query, $this->direction);
        } elseif (is_string($column)) {
            $query->orderBy($column, $this->direction);
        }

        if ($tieBreaker !== null && $tieBreaker !== $column) {
            $query->orderBy($tieBreaker, $this->direction);
        }

        return $query;
    }

    /**
     * @param  Builder<*>|Relation<*, *, *>  $query
     * @return Builder<*>
     */
    private function eloquentQuery(Builder|Relation $query): Builder
    {
        return $query instanceof Relation ? $query->getQuery() : $query;
    }

    /**
     * @template T
     *
     * @param  Collection<int, T>  $rows
     * @param  array<string, callable(T): mixed>  $accessors
     * @return Collection<int, T>
     */
    public function sortMapped(Collection $rows, array $accessors): Collection
    {
        $accessor = $accessors[$this->sort] ?? null;

        if ($accessor === null) {
            return $rows->values();
        }

        $sorted = $this->direction === 'asc'
            ? $rows->sortBy($accessor, SORT_NATURAL | SORT_FLAG_CASE)
            : $rows->sortByDesc($accessor, SORT_NATURAL | SORT_FLAG_CASE);

        return $sorted->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'q' => $this->search,
            'sort' => $this->sort,
            'direction' => $this->direction,
            ...$this->filters,
        ];
    }

    public function isFiltered(): bool
    {
        if ($this->search !== '') {
            return true;
        }

        foreach ($this->filters as $value) {
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    public function searchTerm(): string
    {
        return '%'.addcslashes($this->search, '%_\\').'%';
    }

    public static function moneyToCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $unsigned = ltrim($amount, '+-');
        [$whole, $decimal] = array_pad(explode('.', $unsigned, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $decimal;

        return $negative ? -$cents : $cents;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function statusOptions(
        string $activeLabel = 'Ativas',
        string $inactiveLabel = 'Inativas',
    ): array {
        return [
            ['value' => 'active', 'label' => $activeLabel],
            ['value' => 'inactive', 'label' => $inactiveLabel],
        ];
    }
}
