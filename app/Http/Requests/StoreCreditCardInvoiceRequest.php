<?php

namespace App\Http\Requests;

use App\Models\CreditCard;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditCardInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $workspace = $currentWorkspace->get();
        abort_if($workspace === null, 403);

        return [
            'credit_card_id' => [
                'required',
                'integer',
                Rule::exists(CreditCard::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)),
            ],
            'reference_month' => ['required', 'date_format:Y-m'],
            'due_date' => ['required', 'date'],
            'statement_amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:9999999999999.99',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'credit_card_id' => 'cartão',
            'reference_month' => 'mês de referência',
            'due_date' => 'vencimento',
            'statement_amount' => 'valor da fatura',
        ];
    }
}
