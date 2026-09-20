<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\FinancialAccount;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditCardInvoicePaymentRequest extends FormRequest
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
            'financial_account_id' => [
                'required',
                'integer',
                Rule::exists(FinancialAccount::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)),
            ],
            'paid_on' => ['required', 'date'],
            'amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:9999999999999.99',
            ],
            'payment_method' => [
                'required',
                Rule::in(array_map(
                    fn (array $option): string => $option['value'],
                    PaymentMethod::invoiceOptions(),
                )),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'financial_account_id' => 'conta de pagamento',
            'paid_on' => 'data de pagamento',
            'amount' => 'valor pago',
            'payment_method' => 'forma de pagamento',
            'notes' => 'observações',
        ];
    }
}
