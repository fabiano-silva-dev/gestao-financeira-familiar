<?php

namespace App\Http\Requests;

use App\Models\FinancialAccount;
use App\Rules\OfxFile;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfxImportRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:5120', new OfxFile],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'financial_account_id' => 'conta',
            'file' => 'arquivo OFX',
        ];
    }
}
