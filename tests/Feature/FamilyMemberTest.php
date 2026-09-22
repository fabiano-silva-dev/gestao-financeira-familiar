<?php

namespace Tests\Feature;

use App\Models\FamilyMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FamilyMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_family_members(): void
    {
        $this->get(route('family-members.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_only_lists_members_from_current_workspace(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);

        $visibleMember = FamilyMember::factory()
            ->for($currentWorkspace)
            ->create(['name' => 'Pessoa visível']);
        FamilyMember::factory()
            ->for($otherWorkspace)
            ->create(['name' => 'Pessoa de outro workspace']);

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->get(route('family-members.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('family-members/index')
                ->has('members', 1)
                ->where('members.0.id', $visibleMember->id)
                ->where('members.0.name', 'Pessoa visível')
            );
    }

    public function test_user_can_create_member_in_current_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('family-members.store'), [
                'name' => 'Ana Silva',
            ])
            ->assertRedirect(route('family-members.index'))
            ->assertSessionHasNoErrors();

        $member = FamilyMember::query()->sole();

        $this->assertSame($workspace->id, $member->workspace_id);
        $this->assertSame('Ana Silva', $member->name);
        $this->assertTrue($member->is_active);
    }

    public function test_user_can_update_member_from_current_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $member = FamilyMember::factory()
            ->for($workspace)
            ->create(['name' => 'Nome anterior']);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('family-members.update', $member), [
                'name' => 'Nome atualizado',
            ])
            ->assertRedirect(route('family-members.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Nome atualizado', $member->fresh()->name);
    }

    public function test_member_from_another_active_workspace_cannot_be_changed(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherMember = FamilyMember::factory()
            ->for($otherWorkspace)
            ->create(['name' => 'Nome protegido']);

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->put(route('family-members.update', $otherMember), [
                'name' => 'Tentativa indevida',
            ])
            ->assertNotFound();

        $this->assertSame('Nome protegido', $otherMember->fresh()->name);
    }

    public function test_user_can_deactivate_and_reactivate_member(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $member = FamilyMember::factory()
            ->for($workspace)
            ->create(['is_active' => true]);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->patch(route('family-members.toggle-status', $member))
            ->assertRedirect(route('family-members.index'));

        $this->assertFalse($member->fresh()->is_active);

        $request->patch(route('family-members.toggle-status', $member))
            ->assertRedirect(route('family-members.index'));

        $this->assertTrue($member->fresh()->is_active);
    }

    public function test_index_filters_and_sorts_members(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        FamilyMember::factory()->for($workspace)->create([
            'name' => 'Bruno',
            'is_active' => true,
        ]);
        $ana = FamilyMember::factory()->for($workspace)->create([
            'name' => 'Ana',
            'is_active' => false,
        ]);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->get(route('family-members.index', [
            'q' => 'Ana',
            'sort' => 'name',
            'direction' => 'asc',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('members', 1)
                ->where('members.0.id', $ana->id)
                ->where('filters.sort', 'name')
            );

        $request->get(route('family-members.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('members', 1)
                ->where('members.0.name', 'Ana')
            );
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
