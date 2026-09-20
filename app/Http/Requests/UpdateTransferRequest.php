<?php

namespace App\Http\Requests;

use App\Enums\FinancialTransactionStatus;
use App\Models\FinancialAccount;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransferRequest extends FormRequest
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

        $accountExists = fn (): mixed => Rule::exists(FinancialAccount::class, 'id')
            ->where(fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspace->id));

        return [
            'transaction_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'source_account_id' => ['required', 'integer', $accountExists()],
            'destination_account_id' => [
                'required',
                'integer',
                'different:source_account_id',
                $accountExists(),
            ],
            'status' => [
                'required',
                Rule::in([
                    FinancialTransactionStatus::Planned->value,
                    FinancialTransactionStatus::Confirmed->value,
                    FinancialTransactionStatus::Cancelled->value,
                ]),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'transaction_date' => 'data',
            'description' => 'descrição',
            'amount' => 'valor',
            'source_account_id' => 'conta de origem',
            'destination_account_id' => 'conta de destino',
            'status' => 'situação',
            'notes' => 'observações',
        ];
    }
}
