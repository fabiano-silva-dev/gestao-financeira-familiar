<?php

namespace App\Http\Requests;

use App\Enums\ClassificationRuleMatchType;
use App\Enums\FinancialTransactionType;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Rules\UniqueClassificationPattern;
use App\Services\Finance\ClassificationRuleMatcher;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('category_id') === '' || $this->input('category_id') === 'none') {
            $this->merge(['category_id' => null]);
        }

        if ($this->input('counterpart_account_id') === '' || $this->input('counterpart_account_id') === 'none') {
            $this->merge(['counterpart_account_id' => null]);
        }

        if ($this->input('payee_name') === '') {
            $this->merge(['payee_name' => null]);
        }

        $action = FinancialTransactionType::tryFrom((string) $this->input('action_type'));

        if ($action === FinancialTransactionType::Transfer) {
            $this->merge(['category_id' => null]);
        }

        if ($action !== FinancialTransactionType::Transfer) {
            $this->merge(['counterpart_account_id' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(
        CurrentWorkspace $currentWorkspace,
        ClassificationRuleMatcher $matcher,
    ): array {
        $workspace = $currentWorkspace->get();
        abort_if($workspace === null, 403);
        $action = FinancialTransactionType::tryFrom((string) $this->input('action_type'));
        $isTransfer = $action === FinancialTransactionType::Transfer;

        return [
            'name' => ['required', 'string', 'max:120'],
            'match_type' => ['required', Rule::enum(ClassificationRuleMatchType::class)],
            'pattern' => [
                'required',
                'string',
                'max:255',
                new UniqueClassificationPattern(
                    $workspace,
                    $matcher,
                    ClassificationRuleMatchType::tryFrom((string) $this->input('match_type')),
                    $this->ignoreRuleId(),
                ),
            ],
            'action_type' => ['required', Rule::enum(FinancialTransactionType::class)],
            'payee_name' => ['nullable', 'string', 'max:160'],
            'category_id' => [
                Rule::requiredIf(! $isTransfer),
                'nullable',
                'integer',
                Rule::exists(Category::class, 'id')
                    ->where(function (Builder $query) use ($workspace, $action): Builder {
                        $query
                            ->where('workspace_id', $workspace->id)
                            ->where('is_active', true);

                        if ($action === FinancialTransactionType::Income
                            || $action === FinancialTransactionType::Expense) {
                            $query->where('type', $action->value);
                        }

                        return $query;
                    }),
            ],
            'counterpart_account_id' => [
                Rule::requiredIf($isTransfer),
                'nullable',
                'integer',
                Rule::exists(FinancialAccount::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)
                        ->where('is_active', true)),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'match_type' => 'forma de correspondência',
            'pattern' => 'texto da regra',
            'action_type' => 'tipo',
            'payee_name' => 'empresa ou beneficiário',
            'category_id' => 'categoria',
            'counterpart_account_id' => 'conta',
        ];
    }

    protected function ignoreRuleId(): ?int
    {
        return null;
    }
}
