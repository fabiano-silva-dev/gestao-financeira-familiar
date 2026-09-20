<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_workspace_have_many_to_many_relationship(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        $this->assertTrue($user->workspaces()->whereKey($workspace->id)->exists());
        $this->assertTrue($workspace->users()->whereKey($user->id)->exists());
    }

    public function test_user_can_activate_and_access_own_workspace(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        $this->actingAs($user)
            ->post(route('workspaces.activate', $workspace))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas(CurrentWorkspace::SESSION_KEY, $workspace->id);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('workspace.current.id', $workspace->id)
                ->where('workspace.current.name', $workspace->name)
            );
    }

    public function test_user_cannot_activate_another_users_workspace(): void
    {
        $user = User::factory()->create();
        $ownWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($ownWorkspace, ['role' => 'owner']);

        $otherUser = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        $otherUser->workspaces()->attach($otherWorkspace, ['role' => 'owner']);

        $this->actingAs($user)
            ->post(route('workspaces.activate', $otherWorkspace))
            ->assertForbidden();
    }

    public function test_tampered_workspace_session_cannot_expose_another_users_workspace(): void
    {
        $user = User::factory()->create();
        $ownWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($ownWorkspace, ['role' => 'owner']);

        $otherUser = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        $otherUser->workspaces()->attach($otherWorkspace, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $otherWorkspace->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas(CurrentWorkspace::SESSION_KEY, $ownWorkspace->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.current.id', $ownWorkspace->id)
                ->where('workspace.current.name', $ownWorkspace->name)
            );
    }
}
