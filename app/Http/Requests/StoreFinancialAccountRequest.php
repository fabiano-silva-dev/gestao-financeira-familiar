<?php

namespace App\Http\Requests;

use App\Enums\FinancialAccountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'institution' => ['nullable', 'string', 'max:120'],
            'type' => ['required', Rule::enum(FinancialAccountType::class)],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'between:-9999999999999.99,9999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'institution' => 'instituição',
            'type' => 'tipo',
            'opening_balance' => 'saldo inicial',
        ];
    }
}
