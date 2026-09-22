<?php

namespace App\Http\Requests;

use App\Enums\ExpenseRefundDestination;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRefundRequest extends FormRequest
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
            'amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:9999999999999.99',
            ],
            'refunded_on' => ['required', 'date'],
            'destination_type' => [
                'required',
                Rule::enum(ExpenseRefundDestination::class),
            ],
            'destination_account_id' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('destination_type')
                        === ExpenseRefundDestination::Account->value,
                ),
                'nullable',
                'integer',
                Rule::exists(FinancialAccount::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)),
            ],
            'credit_card_invoice_id' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('destination_type')
                        === ExpenseRefundDestination::CreditCard->value,
                ),
                'nullable',
                'integer',
                Rule::exists(CreditCardInvoice::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'amount' => 'valor do reembolso',
            'refunded_on' => 'data do reembolso',
            'destination_type' => 'destino do reembolso',
            'destination_account_id' => 'conta de destino',
            'credit_card_invoice_id' => 'fatura de destino',
            'notes' => 'observações',
        ];
    }
}
