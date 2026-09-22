<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestAiCredentialRequest extends FormRequest
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
            'provider' => ['required', 'string', Rule::in(['gemini', 'groq'])],
            'gemini_api_key' => ['nullable', 'string', 'max:500'],
            'groq_api_key' => ['nullable', 'string', 'max:500'],
        ];
    }
}
