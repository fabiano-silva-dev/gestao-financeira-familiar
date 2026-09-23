<?php

namespace App\Http\Requests;

use App\Enums\FinancialTransactionType;
use App\Models\Category;
use App\Models\CreditCard;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'purchases' => ['sometimes', 'array', 'max:200'],
            'purchases.*.purchased_on' => ['required', 'date'],
            'purchases.*.description' => ['required', 'string', 'max:255'],
            'purchases.*.amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:9999999999999.99',
            ],
            'purchases.*.installment_number' => ['required', 'integer', 'min:1', 'max:999'],
            'purchases.*.total_installments' => ['required', 'integer', 'min:1', 'max:999'],
            'purchases.*.category_id' => [
                'nullable',
                'integer',
                Rule::exists(Category::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)
                        ->where('type', FinancialTransactionType::Expense->value)),
            ],
            'purchases.*.payee_name' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('purchases', []) as $index => $purchase) {
                    if (! is_array($purchase)) {
                        continue;
                    }

                    $current = (int) ($purchase['installment_number'] ?? 0);
                    $total = (int) ($purchase['total_installments'] ?? 0);

                    if ($current > 0 && $total > 0 && $current > $total) {
                        $validator->errors()->add(
                            "purchases.{$index}.total_installments",
                            'O total de parcelas não pode ser menor que a parcela atual.',
                        );
                    }
                }
            },
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
            'purchases.*.purchased_on' => 'data da compra',
            'purchases.*.description' => 'descrição da compra',
            'purchases.*.amount' => 'valor da compra',
            'purchases.*.installment_number' => 'parcela atual',
            'purchases.*.total_installments' => 'total de parcelas',
            'purchases.*.category_id' => 'categoria',
            'purchases.*.payee_name' => 'favorecido',
        ];
    }
}
