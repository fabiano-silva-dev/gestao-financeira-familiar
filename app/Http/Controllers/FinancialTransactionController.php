<?php

namespace App\Http\Controllers;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Http\Requests\SaveFinancialEntryRequest;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FinancialTransactionController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly FinancialEntryService $entryService,
    ) {}

    public function index(): Response
    {
        $entries = $this->workspace()
            ->financialTransactions()
            ->whereIn('type', [
                FinancialTransactionType::Income,
                FinancialTransactionType::Expense,
            ])
            ->with([
                'account:id,name',
                'creditCard:id,name,last_four',
                'category:id,name,parent_id',
                'category.parent:id,name',
                'familyMember:id,name',
            ])
            ->withCount('installments')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (FinancialTransaction $entry): array => $this->entryData($entry));

        return Inertia::render('transactions/index', [
            'entries' => $entries,
        ]);
    }

    public function createExpense(): Response
    {
        return $this->createResponse(FinancialTransactionType::Expense);
    }

    public function createIncome(): Response
    {
        return $this->createResponse(FinancialTransactionType::Income);
    }

    public function store(SaveFinancialEntryRequest $request): RedirectResponse
    {
        $entry = $this->entryService->create(
            $this->workspace(),
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $entry->type === FinancialTransactionType::Expense
                ? 'Despesa cadastrada com sucesso.'
                : 'Receita cadastrada com sucesso.',
        ]);

        return to_route('transactions.index');
    }

    public function edit(int $entry): Response
    {
        $financialEntry = $this->findEntry($entry);

        return Inertia::render('transactions/edit', [
            'entry' => $this->entryData($financialEntry),
            ...$this->referenceOptions($financialEntry->type),
        ]);
    }

    public function update(
        SaveFinancialEntryRequest $request,
        int $entry,
    ): RedirectResponse {
        $this->entryService->update(
            $this->findEntry($entry),
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Lançamento atualizado com sucesso.',
        ]);

        return to_route('transactions.index');
    }

    public function advanceStatus(int $entry): RedirectResponse
    {
        $financialEntry = $this->entryService->advanceStatus(
            $this->findEntry($entry),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($financialEntry->status) {
                FinancialTransactionStatus::Confirmed => 'Lançamento confirmado com sucesso.',
                FinancialTransactionStatus::Cancelled => 'Lançamento cancelado com sucesso.',
                FinancialTransactionStatus::Planned => 'Lançamento marcado como planejado.',
            },
        ]);

        return to_route('transactions.index');
    }

    public function toggleSettlement(int $entry): RedirectResponse
    {
        $financialEntry = $this->entryService->toggleSettlement(
            $this->findEntry($entry),
        );
        $isExpense = $financialEntry->type === FinancialTransactionType::Expense;

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $financialEntry->settled_on !== null
                ? ($isExpense
                    ? 'Pagamento registrado com sucesso.'
                    : 'Recebimento registrado com sucesso.')
                : ($isExpense
                    ? 'Pagamento desfeito com sucesso.'
                    : 'Recebimento desfeito com sucesso.'),
        ]);

        return to_route('transactions.index');
    }

    private function createResponse(FinancialTransactionType $type): Response
    {
        return Inertia::render('transactions/create', [
            'entryType' => $type->value,
            'entryTypeLabel' => $type->label(),
            'defaultDate' => now()->toDateString(),
            ...$this->referenceOptions($type),
        ]);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findEntry(int $entry): FinancialTransaction
    {
        return $this->workspace()
            ->financialTransactions()
            ->whereIn('type', [
                FinancialTransactionType::Income,
                FinancialTransactionType::Expense,
            ])
            ->with([
                'account:id,name',
                'creditCard:id,name,last_four',
                'category:id,name,parent_id',
                'category.parent:id,name',
                'familyMember:id,name',
            ])
            ->withCount('installments')
            ->findOrFail($entry);
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceOptions(FinancialTransactionType $categoryType): array
    {
        $workspace = $this->workspace();

        return [
            'accountOptions' => $workspace->financialAccounts()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (FinancialAccount $account): array => $this->referenceData($account))
                ->all(),
            'cardOptions' => $workspace->creditCards()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (CreditCard $card): array => [
                    ...$this->referenceData($card),
                    'label' => "{$card->name} · final {$card->last_four}",
                ])
                ->all(),
            'categoryOptions' => $workspace->categories()
                ->where('type', $categoryType->value)
                ->with('parent:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category): array => [
                    ...$this->referenceData($category),
                    'type' => $category->type->value,
                    'label' => $category->parent === null
                        ? $category->name
                        : "{$category->parent->name} / {$category->name}",
                ])
                ->all(),
            'memberOptions' => $workspace->familyMembers()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (FamilyMember $member): array => $this->referenceData($member))
                ->all(),
            'paymentMethods' => PaymentMethod::options(),
        ];
    }

    /**
     * @return array{id: int, name: string, is_active: bool}
     */
    private function referenceData(
        FinancialAccount|CreditCard|Category|FamilyMember $model,
    ): array {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'is_active' => $model->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryData(FinancialTransaction $entry): array
    {
        $categoryName = $entry->category?->name;

        if ($entry->category?->parent !== null) {
            $categoryName = "{$entry->category->parent->name} / {$entry->category->name}";
        }

        return [
            'id' => $entry->id,
            'type' => $entry->type->value,
            'type_label' => $entry->type->label(),
            'transaction_date' => $entry->transaction_date->toDateString(),
            'competence_date' => $entry->competence_date?->toDateString()
                ?? $entry->transaction_date->toDateString(),
            'description' => $entry->description,
            'amount' => $entry->amount,
            'financial_account_id' => $entry->financial_account_id,
            'financial_account_name' => $entry->account?->name,
            'credit_card_id' => $entry->credit_card_id,
            'credit_card_name' => $entry->creditCard === null
                ? null
                : "{$entry->creditCard->name} · final {$entry->creditCard->last_four}",
            'installment_count' => $entry->credit_card_id === null
                ? 1
                : max(1, (int) ($entry->getAttribute('installments_count') ?? 0)),
            'category_id' => $entry->category_id,
            'category_name' => $categoryName,
            'family_member_id' => $entry->family_member_id,
            'family_member_name' => $entry->familyMember?->name,
            'payment_method' => $entry->payment_method?->value,
            'payment_method_label' => $entry->payment_method?->label(),
            'payee_name' => $entry->payee_name,
            'payment_instructions' => $entry->payment_instructions,
            'due_date' => $entry->due_date?->toDateString(),
            'settled_on' => $entry->settled_on?->toDateString(),
            'is_settled' => $entry->settled_on !== null,
            'status' => $entry->status->value,
            'status_label' => $entry->status->label(),
            'notes' => $entry->notes,
            'origin' => $entry->origin->value,
            'financial_recurrence_id' => $entry->financial_recurrence_id,
            'recurrence_is_overridden' => $entry->recurrence_is_overridden,
        ];
    }
}
