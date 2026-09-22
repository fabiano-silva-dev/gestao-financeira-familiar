<?php

namespace App\Http\Requests;

use App\Enums\FinancialAccountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancialAccountRequest extends FormRequest
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
            'agency' => ['nullable', 'string', 'max:40'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'type' => ['required', Rule::enum(FinancialAccountType::class)],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'between:-9999999999999.99,9999999999999.99'],
            'opening_balance_date' => ['required', 'date'],
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
            'agency' => 'agência',
            'account_number' => 'número da conta',
            'type' => 'tipo',
            'opening_balance' => 'saldo inicial',
            'opening_balance_date' => 'data do saldo inicial',
        ];
    }
}
