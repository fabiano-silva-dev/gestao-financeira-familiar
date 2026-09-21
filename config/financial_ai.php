<?php

return [
    'enabled' => filter_var(
        env('FINANCIAL_AI_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN,
    ),
    'timeout_seconds' => (int) env('FINANCIAL_AI_TIMEOUT_SECONDS', 30),
    'batch_size' => (int) env('FINANCIAL_AI_BATCH_SIZE', 40),
    'merchant_min_confidence' => (float) env(
        'FINANCIAL_AI_MERCHANT_MIN_CONFIDENCE',
        0.70,
    ),
    'category_min_confidence' => (float) env(
        'FINANCIAL_AI_CATEGORY_MIN_CONFIDENCE',
        0.80,
    ),
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'models' => array_values(array_filter(array_map(
            'trim',
            explode(
                ',',
                (string) env(
                    'FINANCIAL_AI_GEMINI_MODELS',
                    'gemini-2.5-flash-lite,gemini-2.5-flash',
                ),
            ),
        ))),
    ],
    'groq' => [
        'api_key' => env('GROQ_API_KEY', ''),
        'models' => array_values(array_filter(array_map(
            'trim',
            explode(
                ',',
                (string) env(
                    'FINANCIAL_AI_GROQ_MODELS',
                    'meta-llama/llama-4-scout-17b-16e-instruct',
                ),
            ),
        ))),
    ],
];
