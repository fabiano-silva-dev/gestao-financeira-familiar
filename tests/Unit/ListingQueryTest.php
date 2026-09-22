<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Support\Listings\ListingQuery;
use Illuminate\Http\Request;
use Tests\TestCase;

class ListingQueryTest extends TestCase
{
    public function test_it_uses_defaults_and_ignores_invalid_sort(): void
    {
        $listing = ListingQuery::from(
            Request::create('/family-members', 'GET', [
                'sort' => 'password',
                'direction' => 'sideways',
            ]),
            ['name', 'status'],
            'status',
            'desc',
            ['status'],
        );

        $this->assertSame('', $listing->search);
        $this->assertSame('status', $listing->sort);
        $this->assertSame('desc', $listing->direction);
        $this->assertNull($listing->filter('status'));
        $this->assertFalse($listing->isFiltered());
    }

    public function test_it_keeps_search_and_allowed_filters(): void
    {
        $listing = ListingQuery::from(
            Request::create('/family-members', 'GET', [
                'q' => 'Ana',
                'sort' => 'name',
                'direction' => 'asc',
                'status' => 'active',
            ]),
            ['name', 'status'],
            'status',
            'desc',
            ['status'],
        );

        $this->assertSame('Ana', $listing->search);
        $this->assertSame('name', $listing->sort);
        $this->assertSame('asc', $listing->direction);
        $this->assertTrue($listing->booleanFilter('status'));
        $this->assertTrue($listing->isFiltered());
        $this->assertSame('%Ana%', $listing->searchTerm());
    }

    public function test_money_to_cents_keeps_precision(): void
    {
        $this->assertSame(125045, ListingQuery::moneyToCents('1250.45'));
        $this->assertSame(-990, ListingQuery::moneyToCents('-9.90'));
    }

    public function test_apply_sort_accepts_eloquent_relation(): void
    {
        $listing = ListingQuery::from(
            Request::create('/transactions', 'GET', [
                'sort' => 'name',
                'direction' => 'asc',
            ]),
            ['name'],
            'name',
            'asc',
        );
        $workspace = new Workspace;
        $workspace->id = 1;

        $query = $listing->applySort($workspace->familyMembers(), [
            'name' => 'name',
        ]);

        $this->assertStringContainsString('order by', strtolower($query->toSql()));
    }
}
