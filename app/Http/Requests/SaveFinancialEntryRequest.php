<?php

namespace App\Http\Requests;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFinancialEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $defaults = [];

        if (! $this->has('competence_date') && $this->filled('transaction_date')) {
            $defaults['competence_date'] = $this->input('transaction_date');
        }

        if (
            ! $this->has('settled_on')
            && $this->input('status') === FinancialTransactionStatus::Confirmed->value
            && $this->input('payment_method') !== PaymentMethod::CreditCard->value
            && $this->filled('transaction_date')
        ) {
            $defaults['settled_on'] = $this->input('transaction_date');
        }

        if ($defaults !== []) {
            $this->merge($defaults);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $workspace = $currentWorkspace->get();
        abort_if($workspace === null, 403);

        $type = FinancialTransactionType::tryFrom((string) $this->input('type'));
        $status = FinancialTransactionStatus::tryFrom((string) $this->input('status'));
        $paymentMethod = PaymentMethod::tryFrom((string) $this->input('payment_method'));
        $isCreditCardExpense = $type === FinancialTransactionType::Expense
            && $paymentMethod === PaymentMethod::CreditCard;
        $isSettled = filled($this->input('settled_on'));
        $isCreate = $this->routeIs('transactions.store');

        $existsInWorkspace = fn (string $model): mixed => Rule::exists($model, 'id')
            ->where(fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspace->id));

        $allowedStatuses = $isCreate
            ? [FinancialTransactionStatus::Planned, FinancialTransactionStatus::Confirmed]
            : FinancialTransactionStatus::cases();

        if ($isCreditCardExpense) {
            $allowedStatuses = $isCreate
                ? [FinancialTransactionStatus::Confirmed]
                : [FinancialTransactionStatus::Confirmed, FinancialTransactionStatus::Cancelled];
        }

        return [
            'type' => [
                'required',
                Rule::in([
                    FinancialTransactionType::Income->value,
                    FinancialTransactionType::Expense->value,
                ]),
            ],
            'transaction_date' => ['required', 'date'],
            'competence_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'financial_account_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(! $isCreditCardExpense),
                $existsInWorkspace(FinancialAccount::class),
            ],
            'credit_card_id' => [
                'nullable',
                'integer',
                Rule::requiredIf($isCreditCardExpense),
                Rule::prohibitedIf(! $isCreditCardExpense),
                $existsInWorkspace(CreditCard::class),
            ],
            'installment_count' => [
                'nullable',
                'integer',
                'min:1',
                'max:60',
                Rule::prohibitedIf(! $isCreditCardExpense),
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
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payee_name' => ['nullable', 'string', 'max:160'],
            'payment_instructions' => ['nullable', 'string', 'max:500'],
            'due_date' => [
                'nullable',
                'date',
                Rule::requiredIf(
                    ! $isCreditCardExpense
                    && (
                        $status === FinancialTransactionStatus::Planned
                        || ($status === FinancialTransactionStatus::Confirmed && ! $isSettled)
                    ),
                ),
                Rule::prohibitedIf($isCreditCardExpense),
            ],
            'settled_on' => [
                'nullable',
                'date',
                Rule::prohibitedIf(
                    $isCreditCardExpense
                    || $status !== FinancialTransactionStatus::Confirmed,
                ),
            ],
            'status' => [
                'required',
                Rule::in(array_map(
                    fn (FinancialTransactionStatus $allowedStatus): string => $allowedStatus->value,
                    $allowedStatuses,
                )),
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
            'type' => 'tipo',
            'transaction_date' => 'data do fato financeiro',
            'competence_date' => 'competência',
            'description' => 'descrição',
            'amount' => 'valor',
            'financial_account_id' => 'conta',
            'credit_card_id' => 'cartão',
            'installment_count' => 'quantidade de parcelas',
            'category_id' => 'categoria',
            'family_member_id' => 'pessoa',
            'payment_method' => 'forma de pagamento',
            'payee_name' => 'favorecido ou pagador',
            'payment_instructions' => 'instruções de pagamento',
            'due_date' => 'vencimento',
            'settled_on' => 'data efetiva de pagamento ou recebimento',
            'status' => 'situação',
            'notes' => 'observações',
        ];
    }
}
