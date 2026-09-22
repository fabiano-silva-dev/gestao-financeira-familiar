<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_ai_settings(): void
    {
        $this->get(route('ai-settings.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_view_ai_settings(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('ai-settings.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/ai')
                ->where('geminiConfigured', false)
                ->where('groqConfigured', false)
                ->where('geminiStored', false)
                ->where('groqStored', false)
            );
    }

    public function test_user_can_save_encrypted_keys_without_exposing_them(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('ai-settings.update'), [
                'gemini_api_key' => 'gemini-secret-key',
                'groq_api_key' => 'groq-secret-key',
            ])
            ->assertRedirect(route('ai-settings.edit'))
            ->assertSessionHasNoErrors();

        $setting = WorkspaceAiSetting::query()->sole();
        $this->assertSame($workspace->id, $setting->workspace_id);
        $this->assertSame('gemini-secret-key', $setting->gemini_api_key);
        $this->assertSame('groq-secret-key', $setting->groq_api_key);
        $this->assertNotNull($setting->configured_at);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('ai-settings.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('geminiConfigured', true)
                ->where('groqConfigured', true)
                ->where('geminiStored', true)
                ->missing('gemini_api_key')
                ->missing('groq_api_key')
            );
    }

    public function test_blank_fields_keep_existing_keys(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $workspace->aiSetting()->create([
            'gemini_api_key' => 'kept-gemini',
            'groq_api_key' => 'kept-groq',
            'configured_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('ai-settings.update'), [
                'gemini_api_key' => '',
                'groq_api_key' => '',
            ])
            ->assertRedirect(route('ai-settings.edit'));

        $setting = $workspace->aiSetting()->firstOrFail();
        $this->assertSame('kept-gemini', $setting->gemini_api_key);
        $this->assertSame('kept-groq', $setting->groq_api_key);
    }

    public function test_user_can_clear_a_stored_key(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $workspace->aiSetting()->create([
            'gemini_api_key' => 'remove-me',
            'groq_api_key' => 'keep-me',
            'configured_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('ai-settings.update'), [
                'clear_gemini' => '1',
            ])
            ->assertRedirect(route('ai-settings.edit'));

        $setting = $workspace->aiSetting()->firstOrFail();
        $this->assertNull($setting->gemini_api_key);
        $this->assertSame('keep-me', $setting->groq_api_key);
    }

    public function test_user_can_test_a_typed_gemini_key(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['models' => []], 200),
        ]);
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('ai-settings.test'), [
                'provider' => 'gemini',
                'gemini_api_key' => 'typed-key',
            ])
            ->assertRedirect(route('ai-settings.edit'));

        $this->assertTrue(session('ai_test')['ok']);
        $this->assertSame('gemini', session('ai_test')['provider']);
        Http::assertSent(fn ($request): bool => str_contains(
            $request->url(),
            'generativelanguage.googleapis.com',
        ));
    }

    /** @return array{User, Workspace} */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
