<?php

namespace App\Http\Requests;

use App\Models\FinancialAccount;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReconciliationTransferRequest extends FormRequest
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
            'counterpart_account_id' => [
                'required',
                'integer',
                Rule::exists(FinancialAccount::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'counterpart_account_id' => 'conta de contrapartida',
        ];
    }
}
