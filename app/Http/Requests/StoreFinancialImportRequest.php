<?php

namespace App\Http\Requests;

use App\Rules\FinancialImportFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'files' => [
                Rule::requiredIf(! $this->hasFile('file')),
                'nullable',
                'array',
                'min:1',
                'max:10',
            ],
            'files.*' => ['required', 'file', new FinancialImportFile],
            'file' => [
                Rule::requiredIf(! $this->hasFile('files')),
                'nullable',
                'file',
                new FinancialImportFile,
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'files' => 'arquivos',
            'files.*' => 'arquivo',
            'file' => 'arquivo',
        ];
    }
}
