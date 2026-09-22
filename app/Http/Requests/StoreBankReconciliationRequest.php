<?php

namespace App\Http\Requests;

use App\Models\AccountMovement;
use App\Models\FinancialTransaction;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankReconciliationRequest extends FormRequest
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
            'account_movement_id' => [
                'required_without:financial_transaction_id',
                'nullable',
                'integer',
                Rule::exists(AccountMovement::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)),
            ],
            'financial_transaction_id' => [
                'required_without:account_movement_id',
                'nullable',
                'integer',
                Rule::exists(FinancialTransaction::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'account_movement_id' => 'lançamento financeiro',
            'financial_transaction_id' => 'lançamento planejado',
        ];
    }
}
