<?php

namespace App\Http\Requests;

use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\RecurrenceFrequency;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFinancialRecurrenceRequest extends FormRequest
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

        $type = FinancialTransactionType::tryFrom((string) $this->input('type'));
        $paymentMethod = PaymentMethod::tryFrom((string) $this->input('payment_method'));
        $isCreditCardExpense = $type === FinancialTransactionType::Expense
            && $paymentMethod === PaymentMethod::CreditCard;

        $existsInWorkspace = fn (string $model): mixed => Rule::exists($model, 'id')
            ->where(fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspace->id));

        $allowedPaymentMethods = array_map(
            fn (PaymentMethod $method): string => $method->value,
            array_values(array_filter(
                PaymentMethod::cases(),
                fn (PaymentMethod $method): bool => ! (
                    $type === FinancialTransactionType::Income
                    && $method === PaymentMethod::CreditCard
                ),
            )),
        );

        return [
            'type' => [
                'required',
                Rule::in([
                    FinancialTransactionType::Income->value,
                    FinancialTransactionType::Expense->value,
                ]),
            ],
            'description' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'financial_account_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(! $isCreditCardExpense),
                Rule::prohibitedIf($isCreditCardExpense),
                $existsInWorkspace(FinancialAccount::class),
            ],
            'credit_card_id' => [
                'nullable',
                'integer',
                Rule::requiredIf($isCreditCardExpense),
                Rule::prohibitedIf(! $isCreditCardExpense),
                $existsInWorkspace(CreditCard::class),
            ],
            'category_id' => [
                'nullable',
                'integer',
                $existsInWorkspace(Category::class),
            ],
            'family_member_id' => [
                'nullable',
                'integer',
                $existsInWorkspace(FamilyMember::class),
            ],
            'payment_method' => ['required', Rule::in($allowedPaymentMethods)],
            'payee_name' => ['nullable', 'string', 'max:160'],
            'payment_instructions' => ['nullable', 'string', 'max:500'],
            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],
            'interval' => ['required', 'integer', 'min:1', 'max:12'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'tipo',
            'description' => 'descrição',
            'amount' => 'valor',
            'financial_account_id' => 'conta',
            'credit_card_id' => 'cartão',
            'category_id' => 'categoria',
            'family_member_id' => 'pessoa',
            'payment_method' => 'forma de pagamento',
            'payee_name' => 'favorecido ou pagador',
            'payment_instructions' => 'instruções de pagamento',
            'frequency' => 'frequência',
            'interval' => 'intervalo',
            'starts_on' => 'início',
            'ends_on' => 'fim',
            'notes' => 'observações',
        ];
    }
}
