<?php

namespace App\Http\Requests;

use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Rules\BankStatementFile;
use App\Rules\CardStatementFile;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFinancialImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $extension = strtolower((string) $this->file('file')?->getClientOriginalExtension());
        $kind = $this->input('kind');

        if (! in_array($kind, ['statement', 'invoice'], true)) {
            $kind = match (true) {
                in_array($extension, ['ofx', 'qfx', 'pdf'], true) => 'statement',
                in_array($extension, ['xls', 'xlsx'], true) => 'invoice',
                default => $kind,
            };
        }

        $this->merge([
            'kind' => $kind,
            'extension' => $extension,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $workspace = $currentWorkspace->get();
        abort_if($workspace === null, 403);

        $isInvoice = $this->input('kind') === 'invoice';
        $isPdf = $this->input('extension') === 'pdf';

        $existsInWorkspace = fn (string $model): mixed => Rule::exists($model, 'id')
            ->where(fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspace->id));

        return [
            'kind' => ['required', Rule::in(['statement', 'invoice'])],
            'file' => [
                'required',
                'file',
                'max:10240',
                $isInvoice ? new CardStatementFile : new BankStatementFile,
            ],
            'pdf_layout' => [
                Rule::requiredIf($isPdf && ! $isInvoice),
                'nullable',
                'string',
                Rule::in(['banrisul_current_account']),
            ],
            'financial_account_id' => [
                Rule::requiredIf(! $isInvoice),
                'nullable',
                'integer',
                $existsInWorkspace(FinancialAccount::class),
            ],
            'credit_card_id' => [
                Rule::requiredIf($isInvoice),
                'nullable',
                'integer',
                $existsInWorkspace(CreditCard::class),
            ],
            'reference_month' => [
                Rule::requiredIf($isInvoice),
                'nullable',
                'date_format:Y-m',
            ],
            'amount_sign' => [
                Rule::requiredIf($isInvoice),
                'nullable',
                Rule::in(['positive', 'negative', 'auto']),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $this->input('kind') === 'invoice'
                    && $this->input('extension') === 'pdf'
                ) {
                    $validator->errors()->add(
                        'file',
                        'Ainda não há layout de PDF para fatura de cartão. Use CSV ou planilha.',
                    );
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kind' => 'tipo do documento',
            'file' => 'arquivo',
            'pdf_layout' => 'layout do PDF',
            'financial_account_id' => 'conta',
            'credit_card_id' => 'cartão',
            'reference_month' => 'mês da fatura',
            'amount_sign' => 'sinal das compras',
        ];
    }
}
