<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'gemini_api_key' => ['nullable', 'string', 'max:500'],
            'groq_api_key' => ['nullable', 'string', 'max:500'],
            'clear_gemini' => ['sometimes', 'boolean'],
            'clear_groq' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'gemini_api_key' => 'chave do Gemini',
            'groq_api_key' => 'chave do Groq',
        ];
    }
}
