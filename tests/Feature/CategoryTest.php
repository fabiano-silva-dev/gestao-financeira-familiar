<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_categories(): void
    {
        $this->get(route('categories.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_only_lists_category_tree_from_current_workspace(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);

        $visibleCategory = Category::factory()
            ->for($currentWorkspace)
            ->create(['name' => 'Moradia']);
        $visibleSubcategory = Category::factory()
            ->for($currentWorkspace)
            ->create([
                'parent_id' => $visibleCategory->id,
                'name' => 'Aluguel',
            ]);
        Category::factory()
            ->for($otherWorkspace)
            ->create(['name' => 'Categoria de outro workspace']);

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->get(route('categories.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('categories/index')
                ->has('categories', 1)
                ->where('categories.0.id', $visibleCategory->id)
                ->where('categories.0.name', 'Moradia')
                ->has('categories.0.children', 1)
                ->where('categories.0.children.0.id', $visibleSubcategory->id)
                ->where('categories.0.children.0.name', 'Aluguel')
            );
    }

    public function test_user_can_create_category_and_subcategory_in_current_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('categories.store'), [
            'name' => 'Moradia',
            'parent_id' => null,
        ])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHasNoErrors();

        $parent = Category::query()->sole();

        $request->post(route('categories.store'), [
            'name' => 'Energia elétrica',
            'parent_id' => $parent->id,
        ])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHasNoErrors();

        $child = Category::query()
            ->where('name', 'Energia elétrica')
            ->sole();

        $this->assertSame($workspace->id, $parent->workspace_id);
        $this->assertNull($parent->parent_id);
        $this->assertSame($workspace->id, $child->workspace_id);
        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_parent_category_must_belong_to_current_workspace(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherParent = Category::factory()
            ->for($otherWorkspace)
            ->create();

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->post(route('categories.store'), [
                'name' => 'Tentativa indevida',
                'parent_id' => $otherParent->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('categories', [
            'workspace_id' => $currentWorkspace->id,
            'name' => 'Tentativa indevida',
        ]);
    }

    public function test_subcategory_cannot_have_its_own_subcategory(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $parent = Category::factory()->for($workspace)->create();
        $child = Category::factory()
            ->for($workspace)
            ->create(['parent_id' => $parent->id]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('categories.store'), [
                'name' => 'Terceiro nível',
                'parent_id' => $child->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('categories', [
            'workspace_id' => $workspace->id,
            'name' => 'Terceiro nível',
        ]);
    }

    public function test_category_with_children_cannot_become_subcategory(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $category = Category::factory()
            ->for($workspace)
            ->create(['name' => 'Moradia']);
        Category::factory()
            ->for($workspace)
            ->create(['parent_id' => $category->id]);
        $newParent = Category::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('categories.update', $category), [
                'name' => 'Moradia atualizada',
                'parent_id' => $newParent->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $category->refresh();

        $this->assertSame('Moradia', $category->name);
        $this->assertNull($category->parent_id);
    }

    public function test_category_from_another_active_workspace_cannot_be_changed(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherCategory = Category::factory()
            ->for($otherWorkspace)
            ->create(['name' => 'Nome protegido']);

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->put(route('categories.update', $otherCategory), [
                'name' => 'Tentativa indevida',
                'parent_id' => null,
            ])
            ->assertNotFound();

        $this->assertSame('Nome protegido', $otherCategory->fresh()->name);
    }

    public function test_user_can_deactivate_and_reactivate_category(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $category = Category::factory()
            ->for($workspace)
            ->create(['is_active' => true]);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->patch(route('categories.toggle-status', $category))
            ->assertRedirect(route('categories.index'));

        $this->assertFalse($category->fresh()->is_active);

        $request->patch(route('categories.toggle-status', $category))
            ->assertRedirect(route('categories.index'));

        $this->assertTrue($category->fresh()->is_active);
    }

    /**
     * @return array{User, Workspace}
     */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
