<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TestAiCredentialRequest;
use App\Http\Requests\Settings\UpdateAiSettingsRequest;
use App\Models\Workspace;
use App\Services\AI\FinancialAiCredentialsService;
use App\Services\AI\TestFinancialAiCredentialService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly FinancialAiCredentialsService $credentials,
        private readonly TestFinancialAiCredentialService $tester,
    ) {}

    public function edit(): Response
    {
        $workspace = $this->workspace();
        $setting = $workspace->aiSetting;
        $status = $this->credentials->status($workspace);

        return Inertia::render('settings/ai', [
            'geminiConfigured' => $status['gemini'],
            'groqConfigured' => $status['groq'],
            'geminiStored' => (bool) $setting?->hasGemini(),
            'groqStored' => (bool) $setting?->hasGroq(),
            'configuredAt' => $setting?->configured_at?->toIso8601String(),
            'lastTest' => session('ai_test'),
        ]);
    }

    public function update(UpdateAiSettingsRequest $request): RedirectResponse
    {
        $this->credentials->save(
            $this->workspace(),
            $request->input('gemini_api_key'),
            $request->input('groq_api_key'),
            $request->boolean('clear_gemini'),
            $request->boolean('clear_groq'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Chaves de IA salvas.',
        ]);

        return to_route('ai-settings.edit');
    }

    public function test(TestAiCredentialRequest $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $provider = $request->string('provider')->toString();
        $typed = $provider === 'groq'
            ? $request->input('groq_api_key')
            : $request->input('gemini_api_key');
        $apiKey = is_string($typed) ? trim($typed) : '';

        if ($apiKey === '') {
            $apiKey = $provider === 'groq'
                ? $this->credentials->groqApiKey($workspace)
                : $this->credentials->geminiApiKey($workspace);
        }

        $result = $this->tester->test($provider, $apiKey);

        $request->session()->flash('ai_test', [
            'provider' => $provider,
            'ok' => $result['ok'],
            'message' => $result['message'],
        ]);

        Inertia::flash('toast', [
            'type' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
        ]);

        return to_route('ai-settings.edit');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }
}
