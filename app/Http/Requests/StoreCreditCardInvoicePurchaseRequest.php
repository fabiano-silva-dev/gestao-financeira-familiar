<?php

namespace App\Http\Requests;

use App\Enums\FinancialTransactionType;
use App\Models\Category;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCreditCardInvoicePurchaseRequest extends FormRequest
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
            'purchased_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:9999999999999.99',
            ],
            'installment_number' => ['required', 'integer', 'min:1', 'max:999'],
            'total_installments' => ['required', 'integer', 'min:1', 'max:999'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists(Category::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)
                        ->where('type', FinancialTransactionType::Expense->value)),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $current = (int) $this->input('installment_number');
                $total = (int) $this->input('total_installments');

                if ($current > 0 && $total > 0 && $current > $total) {
                    $validator->errors()->add(
                        'total_installments',
                        'O total de parcelas não pode ser menor que a parcela atual.',
                    );
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
            'purchased_on' => 'data da compra',
            'description' => 'descrição da compra',
            'amount' => 'valor da compra',
            'installment_number' => 'parcela atual',
            'total_installments' => 'total de parcelas',
            'category_id' => 'categoria',
        ];
    }
}
