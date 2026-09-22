<?php

namespace App\Services\AI;

use App\Enums\CategoryType;
use App\Models\CardStatementEntry;
use App\Models\Category;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ExpenseClassificationAiService
{
    public function __construct(
        private readonly FinancialAiCredentialsService $credentials,
    ) {}

    /**
     * @param  Collection<int, CardStatementEntry>  $entries
     * @return array{
     *     provider: string,
     *     model: string,
     *     items: array<int, array{
     *         entry_id: int,
     *         merchant_name: string|null,
     *         merchant_confidence: float,
     *         category_id: int|null,
     *         category_confidence: float
     *     }>
     * }|null
     */
    public function classify(
        Workspace $workspace,
        Collection $entries,
    ): ?array {
        if (! $this->credentials->enabled($workspace) || $entries->isEmpty()) {
            return null;
        }

        $prompt = $this->prompt($workspace, $entries);
        $geminiKey = $this->credentials->geminiApiKey($workspace);

        if ($geminiKey !== '') {
            foreach ((array) config('financial_ai.gemini.models', []) as $model) {
                if (! is_string($model) || trim($model) === '') {
                    continue;
                }

                $model = trim($model);
                $items = $this->callGemini($geminiKey, $model, $prompt);

                if ($items !== null) {
                    return [
                        'provider' => 'gemini',
                        'model' => $model,
                        'items' => $items,
                    ];
                }
            }
        }

        $groqKey = $this->credentials->groqApiKey($workspace);

        if ($groqKey !== '') {
            foreach ((array) config('financial_ai.groq.models', []) as $model) {
                if (! is_string($model) || trim($model) === '') {
                    continue;
                }

                $model = trim($model);
                $items = $this->callGroq($groqKey, $model, $prompt);

                if ($items !== null) {
                    return [
                        'provider' => 'groq',
                        'model' => $model,
                        'items' => $items,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, CardStatementEntry>  $entries
     */
    private function prompt(
        Workspace $workspace,
        Collection $entries,
    ): string {
        $categories = $workspace->categories()
            ->where('type', CategoryType::Expense->value)
            ->where('is_active', true)
            ->with('parent:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id'])
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->parent === null
                    ? $category->name
                    : "{$category->parent->name} / {$category->name}",
            ])
            ->values()
            ->all();

        $purchases = $entries
            ->map(fn (CardStatementEntry $entry): array => [
                'entry_id' => $entry->id,
                'date' => $entry->purchased_on->toDateString(),
                'description' => $entry->description,
                'amount' => $entry->amount,
                'installment_number' => $entry->installment_number,
                'total_installments' => $entry->total_installments,
            ])
            ->values()
            ->all();

        $categoriesJson = json_encode(
            $categories,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '[]';
        $purchasesJson = json_encode(
            $purchases,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '[]';

        return <<<PROMPT
Você classifica compras de cartão de crédito para um sistema financeiro familiar brasileiro.

Para cada compra, interprete a descrição da fatura e retorne:
- entry_id: exatamente o id recebido;
- merchant_name: nome comercial ou empresa mais provável associada à despesa, sem códigos de adquirente, prefixos de cartão ou ruído. Se não for possível identificar com segurança, use null;
- merchant_confidence: número entre 0 e 1;
- category_id: escolha SOMENTE um id existente na lista de categorias de despesa abaixo. Nunca invente id ou categoria. Se não houver categoria adequada, use null;
- category_confidence: número entre 0 e 1.

Não altere valor, data, cartão ou parcelamento.
Quando houver dúvida, reduza a confiança ou retorne null.
Retorne SOMENTE JSON válido no formato:
{"items":[{"entry_id":1,"merchant_name":"Empresa","merchant_confidence":0.95,"category_id":10,"category_confidence":0.90}]}

Categorias válidas:
{$categoriesJson}

Compras:
{$purchasesJson}
PROMPT;
    }

    /**
     * @return array<int, array{
     *     entry_id: int,
     *     merchant_name: string|null,
     *     merchant_confidence: float,
     *     category_id: int|null,
     *     category_confidence: float
     * }>|null
     */
    private function callGemini(
        string $apiKey,
        string $model,
        string $prompt,
    ): ?array {
        try {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                .$model.':generateContent?key='.urlencode($apiKey);
            $response = Http::timeout(
                (int) config('financial_ai.timeout_seconds', 30),
            )->post($url, [
                'contents' => [[
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('IA financeira: falha no Gemini.', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        return is_string($text) ? $this->parseItems($text) : null;
    }

    /**
     * @return array<int, array{
     *     entry_id: int,
     *     merchant_name: string|null,
     *     merchant_confidence: float,
     *     category_id: int|null,
     *     category_confidence: float
     * }>|null
     */
    private function callGroq(
        string $apiKey,
        string $model,
        string $prompt,
    ): ?array {
        try {
            $response = Http::timeout(
                (int) config('financial_ai.timeout_seconds', 30),
            )
                ->withToken($apiKey)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Retorne somente JSON válido, sem markdown.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);
        } catch (\Throwable $exception) {
            Log::warning('IA financeira: falha no Groq.', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $text = $response->json('choices.0.message.content');

        return is_string($text) ? $this->parseItems($text) : null;
    }

    /**
     * @return array<int, array{
     *     entry_id: int,
     *     merchant_name: string|null,
     *     merchant_confidence: float,
     *     category_id: int|null,
     *     category_confidence: float
     * }>|null
     */
    private function parseItems(string $text): ?array
    {
        $data = $this->parseJson($text);
        $rawItems = is_array($data) ? ($data['items'] ?? null) : null;

        if (! is_array($rawItems)) {
            return null;
        }

        $items = [];

        foreach ($rawItems as $rawItem) {
            if (! is_array($rawItem)) {
                continue;
            }

            $entryId = filter_var(
                $rawItem['entry_id'] ?? null,
                FILTER_VALIDATE_INT,
            );

            if (! is_int($entryId) || $entryId <= 0) {
                continue;
            }

            $merchantName = $rawItem['merchant_name'] ?? null;
            $merchantName = is_string($merchantName)
                ? trim(mb_substr($merchantName, 0, 160))
                : null;
            $merchantName = $merchantName === '' ? null : $merchantName;
            $categoryId = filter_var(
                $rawItem['category_id'] ?? null,
                FILTER_VALIDATE_INT,
            );

            $items[] = [
                'entry_id' => $entryId,
                'merchant_name' => $merchantName,
                'merchant_confidence' => $this->confidence(
                    $rawItem['merchant_confidence'] ?? 0,
                ),
                'category_id' => is_int($categoryId) && $categoryId > 0
                    ? $categoryId
                    : null,
                'category_confidence' => $this->confidence(
                    $rawItem['category_confidence'] ?? 0,
                ),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
        $data = json_decode(trim($text), true);

        if (is_array($data)) {
            return $data;
        }

        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $data = json_decode($matches[0], true);

            return is_array($data) ? $data : null;
        }

        return null;
    }

    private function confidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $confidence = (float) $value;

        if ($confidence > 1 && $confidence <= 100) {
            $confidence /= 100;
        }

        return max(0.0, min(1.0, $confidence));
    }
}
