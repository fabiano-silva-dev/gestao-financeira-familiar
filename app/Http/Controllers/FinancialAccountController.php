<?php

namespace App\Http\Controllers;

use App\Enums\FinancialAccountType;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Http\Requests\UpdateFinancialAccountRequest;
use App\Models\FinancialAccount;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FinancialAccountController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function index(): Response
    {
        $accounts = $this->workspace()
            ->financialAccounts()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (FinancialAccount $account): array => $this->accountData($account));

        return Inertia::render('accounts/index', [
            'accounts' => $accounts,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('accounts/create', [
            'accountTypes' => FinancialAccountType::options(),
        ]);
    }

    public function store(StoreFinancialAccountRequest $request): RedirectResponse
    {
        $this->workspace()->financialAccounts()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Conta cadastrada com sucesso.',
        ]);

        return to_route('accounts.index');
    }

    public function edit(int $account): Response
    {
        return Inertia::render('accounts/edit', [
            'account' => $this->accountData($this->findAccount($account)),
            'accountTypes' => FinancialAccountType::options(),
        ]);
    }

    public function update(
        UpdateFinancialAccountRequest $request,
        int $account,
    ): RedirectResponse {
        $this->findAccount($account)->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Conta atualizada com sucesso.',
        ]);

        return to_route('accounts.index');
    }

    public function toggleStatus(int $account): RedirectResponse
    {
        $financialAccount = $this->findAccount($account);
        $financialAccount->update([
            'is_active' => ! $financialAccount->is_active,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $financialAccount->is_active
                ? 'Conta ativada com sucesso.'
                : 'Conta desativada com sucesso.',
        ]);

        return to_route('accounts.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findAccount(int $account): FinancialAccount
    {
        return $this->workspace()
            ->financialAccounts()
            ->findOrFail($account);
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     institution: string|null,
     *     type: string,
     *     type_label: string,
     *     opening_balance: string,
     *     is_active: bool
     * }
     */
    private function accountData(FinancialAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'institution' => $account->institution,
            'type' => $account->type->value,
            'type_label' => $account->type->label(),
            'opening_balance' => $account->opening_balance,
            'is_active' => $account->is_active,
        ];
    }
}
