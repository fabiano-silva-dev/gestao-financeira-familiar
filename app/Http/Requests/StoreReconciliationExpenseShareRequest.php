<?php

namespace App\Http\Requests;

use App\Enums\FinancialTransactionType;
use App\Models\FinancialTransaction;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReconciliationExpenseShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->get()?->id ?? 0;

        return [
            'financial_transaction_id' => [
                'required',
                'integer',
                Rule::exists(FinancialTransaction::class, 'id')
                    ->where('workspace_id', $workspaceId)
                    ->where('type', FinancialTransactionType::Expense->value),
            ],
            'expected_shared_amount' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'gt:0',
            ],
        ];
    }
}
