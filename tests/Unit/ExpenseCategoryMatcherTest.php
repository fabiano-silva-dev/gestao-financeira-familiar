<?php

namespace Tests\Unit;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\ExpenseCategoryMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseCategoryMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_source_category_and_merchant_keywords(): void
    {
        $workspace = $this->workspace();
        $transporte = Category::factory()->for($workspace)->create([
            'name' => 'Transporte',
            'type' => CategoryType::Expense,
        ]);
        $mercado = Category::factory()->for($workspace)->create([
            'name' => 'Mercado',
            'type' => CategoryType::Expense,
        ]);
        $matcher = app(ExpenseCategoryMatcher::class);

        $this->assertSame(
            $transporte->id,
            $matcher->match($workspace, 'Uber UberX', 'transporte'),
        );
        $this->assertSame(
            $mercado->id,
            $matcher->match($workspace, 'Stangherlin Supermerca'),
        );
    }

    public function test_does_not_force_short_generic_category_names(): void
    {
        $workspace = $this->workspace();
        Category::factory()->for($workspace)->create([
            'name' => 'Casa',
            'type' => CategoryType::Expense,
        ]);

        $this->assertNull(
            app(ExpenseCategoryMatcher::class)->match($workspace, 'Casa Colonial'),
        );
    }

    private function workspace(): Workspace
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return $workspace;
    }
}
