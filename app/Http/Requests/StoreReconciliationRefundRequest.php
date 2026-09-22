<?php

namespace App\Http\Requests;

use App\Enums\FinancialTransactionType;
use App\Models\FinancialTransaction;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReconciliationRefundRequest extends FormRequest
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
            'financial_transaction_id' => [
                'required',
                'integer',
                Rule::exists(FinancialTransaction::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)
                        ->where('type', FinancialTransactionType::Expense->value)),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'financial_transaction_id' => 'compra ou despesa original',
        ];
    }
}
