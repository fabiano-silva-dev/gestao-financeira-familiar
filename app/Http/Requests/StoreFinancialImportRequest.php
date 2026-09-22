<?php

namespace App\Http\Requests;

use App\Rules\FinancialImportFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFinancialImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->hasFile('file') && ! $this->hasFile('files')) {
            $this->files->set('files', [$this->file('file')]);
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', new FinancialImportFile],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'files' => 'arquivos',
            'files.*' => 'arquivo',
        ];
    }
}
