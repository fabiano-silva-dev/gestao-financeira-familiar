<?php

namespace App\Services\AI;

use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;

final class FinancialAiCredentialsService
{
    public function enabled(?Workspace $workspace = null): bool
    {
        return (bool) config('financial_ai.enabled', true)
            && ($this->geminiApiKey($workspace) !== '' || $this->groqApiKey($workspace) !== '');
    }

    public function geminiApiKey(?Workspace $workspace = null): string
    {
        return $this->firstFilled(
            $workspace?->aiSetting?->gemini_api_key,
            (string) config('financial_ai.gemini.api_key', ''),
        );
    }

    public function groqApiKey(?Workspace $workspace = null): string
    {
        return $this->firstFilled(
            $workspace?->aiSetting?->groq_api_key,
            (string) config('financial_ai.groq.api_key', ''),
        );
    }

    /**
     * @return array{gemini: bool, groq: bool}
     */
    public function status(?Workspace $workspace = null): array
    {
        return [
            'gemini' => $this->geminiApiKey($workspace) !== '',
            'groq' => $this->groqApiKey($workspace) !== '',
        ];
    }

    public function save(
        Workspace $workspace,
        ?string $geminiApiKey,
        ?string $groqApiKey,
        bool $clearGemini = false,
        bool $clearGroq = false,
    ): WorkspaceAiSetting {
        $existing = $workspace->aiSetting;
        $gemini = $clearGemini
            ? null
            : $this->keepOrReplace($geminiApiKey, $existing?->gemini_api_key);
        $groq = $clearGroq
            ? null
            : $this->keepOrReplace($groqApiKey, $existing?->groq_api_key);

        return $workspace->aiSetting()->updateOrCreate(
            [],
            [
                'gemini_api_key' => $gemini,
                'groq_api_key' => $groq,
                'configured_at' => now(),
            ],
        );
    }

    private function keepOrReplace(?string $incoming, ?string $current): ?string
    {
        $incoming = trim((string) $incoming);

        if ($incoming !== '') {
            return $incoming;
        }

        $current = is_string($current) ? trim($current) : '';

        return $current === '' ? null : $current;
    }

    private function firstFilled(mixed $stored, string $fallback): string
    {
        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        return trim($fallback);
    }
}
