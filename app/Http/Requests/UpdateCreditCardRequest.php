<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCreditCardRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'institution' => ['nullable', 'string', 'max:120'],
            'last_four' => ['required', 'string', 'regex:/^\d{4}$/'],
            'holder_id' => [
                'nullable',
                'integer',
                Rule::exists(FamilyMember::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)),
            ],
            'credit_limit' => ['required', 'numeric', 'decimal:0,2', 'between:0,9999999999999.99'],
            'closing_day' => ['required', 'integer', 'between:1,31'],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'payment_account_id' => [
                'nullable',
                'integer',
                Rule::exists(FinancialAccount::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)),
            ],
            'invoice_payment_method' => [
                'required',
                Rule::in(array_column(PaymentMethod::invoiceOptions(), 'value')),
            ],
            'payment_instructions' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'institution' => 'instituição',
            'last_four' => 'final do cartão',
            'holder_id' => 'titular',
            'credit_limit' => 'limite',
            'closing_day' => 'dia de fechamento',
            'due_day' => 'dia de vencimento',
            'payment_account_id' => 'conta de pagamento',
            'invoice_payment_method' => 'forma de pagamento da fatura',
            'payment_instructions' => 'instruções de pagamento',
        ];
    }
}
