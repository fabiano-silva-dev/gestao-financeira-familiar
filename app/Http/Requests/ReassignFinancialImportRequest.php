<?php

namespace App\Http\Requests;

use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReassignFinancialImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $workspace = $currentWorkspace->get();
        abort_if($workspace === null, 403);

        $existsInWorkspace = fn (string $model): mixed => Rule::exists($model, 'id')
            ->where(fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspace->id));

        return [
            'document_type' => [
                'required',
                Rule::in(['bank_statement', 'payment_account_statement', 'credit_card_statement']),
            ],
            'financial_account_id' => [
                Rule::requiredIf(in_array($this->input('document_type'), [
                    'bank_statement',
                    'payment_account_statement',
                ], true)),
                'nullable',
                'integer',
                $existsInWorkspace(FinancialAccount::class),
            ],
            'credit_card_id' => [
                Rule::requiredIf($this->input('document_type') === 'credit_card_statement'),
                'nullable',
                'integer',
                $existsInWorkspace(CreditCard::class),
            ],
            'reference_month' => [
                Rule::requiredIf($this->input('document_type') === 'credit_card_statement'),
                'nullable',
                'date_format:Y-m',
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'document_type' => 'tipo',
            'financial_account_id' => 'conta',
            'credit_card_id' => 'cartão',
            'reference_month' => 'mês de vencimento',
        ];
    }
}
