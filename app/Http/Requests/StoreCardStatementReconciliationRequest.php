<?php

namespace App\Http\Requests;

use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardStatementReconciliationRequest extends FormRequest
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
        $invoice = (int) $this->route('invoice');

        return [
            'transaction_installment_id' => [
                'nullable',
                'required_without:recurrence_transaction_id',
                'integer',
                Rule::exists(TransactionInstallment::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)
                        ->where('credit_card_invoice_id', $invoice)),
            ],
            'recurrence_transaction_id' => [
                'nullable',
                'required_without:transaction_installment_id',
                'integer',
                Rule::exists(FinancialTransaction::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)
                        ->whereNotNull('financial_recurrence_id')
                        ->where('status', 'planned')),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'transaction_installment_id' => 'parcela da compra',
            'recurrence_transaction_id' => 'previsão da recorrência',
        ];
    }
}
