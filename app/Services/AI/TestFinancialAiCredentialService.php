<?php

namespace App\Services\AI;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class TestFinancialAiCredentialService
{
    /**
     * @return array{ok: bool, message: string}
     */
    public function test(string $provider, string $apiKey): array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            $name = $provider === 'groq' ? 'Groq' : 'Gemini';

            return [
                'ok' => false,
                'message' => "Informe a chave do {$name} para testar.",
            ];
        }

        return match ($provider) {
            'gemini' => $this->testGemini($apiKey),
            'groq' => $this->testGroq($apiKey),
            default => ['ok' => false, 'message' => 'Provedor desconhecido.'],
        };
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function testGemini(string $apiKey): array
    {
        try {
            $response = Http::timeout(20)->get(
                'https://generativelanguage.googleapis.com/v1beta/models',
                ['key' => $apiKey, 'pageSize' => 1],
            );
        } catch (\Throwable) {
            return [
                'ok' => false,
                'message' => 'Não deu para falar com o Gemini agora. Tente de novo.',
            ];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Gemini aceitou a chave.'];
        }

        return $this->interpretFailure('Gemini', $response);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function testGroq(string $apiKey): array
    {
        try {
            $response = Http::timeout(20)
                ->withToken($apiKey)
                ->get('https://api.groq.com/openai/v1/models');
        } catch (\Throwable) {
            return [
                'ok' => false,
                'message' => 'Não deu para falar com o Groq agora. Tente de novo.',
            ];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Groq aceitou a chave.'];
        }

        return $this->interpretFailure('Groq', $response);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function interpretFailure(string $name, Response $response): array
    {
        $body = strtolower($response->body());

        if (
            in_array($response->status(), [400, 401, 403], true)
            || str_contains($body, 'api_key_invalid')
            || str_contains($body, 'invalid api key')
            || str_contains($body, 'invalid_api_key')
            || str_contains($body, 'unauthorized')
            || str_contains($body, 'unauthenticated')
        ) {
            return [
                'ok' => false,
                'message' => "Esta chave do {$name} não foi aceita.",
            ];
        }

        if (
            $response->status() === 429
            || str_contains($body, 'resource_exhausted')
            || str_contains($body, 'quota')
        ) {
            return [
                'ok' => true,
                'message' => "A chave do {$name} está certa, mas a cota do período acabou.",
            ];
        }

        return [
            'ok' => false,
            'message' => "Não deu para falar com o {$name} agora. Tente de novo.",
        ];
    }
}
