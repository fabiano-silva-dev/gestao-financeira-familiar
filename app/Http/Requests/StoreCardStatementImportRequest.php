<?php

namespace App\Http\Requests;

use App\Models\CreditCard;
use App\Rules\CardStatementFile;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardStatementImportRequest extends FormRequest
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
            'amount_sign' => ['required', Rule::in(['positive', 'negative', 'auto'])],
            'pdf_layout' => [
                Rule::requiredIf(
                    strtolower((string) $this->file('file')?->getClientOriginalExtension()) === 'pdf',
                ),
                'nullable',
                'string',
                Rule::in(['mercado_pago_credit_card']),
            ],
            'file' => ['required', 'file', 'max:10240', new CardStatementFile],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'credit_card_id' => 'cartão',
            'reference_month' => 'mês da fatura',
            'amount_sign' => 'sinal das compras',
            'pdf_layout' => 'layout do PDF',
            'file' => 'arquivo da fatura',
        ];
    }
}
