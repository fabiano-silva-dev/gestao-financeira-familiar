<?php

namespace App\Http\Controllers;

use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\RecurrenceFrequency;
use App\Http\Requests\SaveFinancialRecurrenceRequest;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Models\FinancialRecurrence;
use App\Models\Workspace;
use App\Services\Finance\FinancialRecurrenceService;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FinancialRecurrenceController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly FinancialRecurrenceService $recurrenceService,
    ) {}

    public function index(): Response
    {
        $workspace = $this->workspace();
        $recurrences = $workspace->financialRecurrences()
            ->with([
                'account:id,name',
                'creditCard:id,name,last_four',
                'category:id,name,parent_id',
                'category.parent:id,name',
                'familyMember:id,name',
            ])
            ->withCount('transactions')
            ->orderByDesc('is_active')
            ->orderBy('description')
            ->get()
            ->map(fn (FinancialRecurrence $recurrence): array => $this->recurrenceData($recurrence));

        return Inertia::render('recurrences/index', [
            'recurrences' => $recurrences,
            'projection' => $this->recurrenceService->monthlyProjection(
                $workspace,
                CarbonImmutable::today(),
            ),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('recurrences/create', [
            'defaultStartDate' => now()->toDateString(),
            ...$this->referenceOptions(),
        ]);
    }

    public function store(
        SaveFinancialRecurrenceRequest $request,
    ): RedirectResponse {
        $this->recurrenceService->create(
            $this->workspace(),
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Recorrência cadastrada e próximos compromissos gerados.',
        ]);

        return to_route('recurrences.index');
    }

    public function edit(int $recurrence): Response
    {
        return Inertia::render('recurrences/edit', [
            'recurrence' => $this->recurrenceData(
                $this->findRecurrence($recurrence),
            ),
            ...$this->referenceOptions(),
        ]);
    }

    public function update(
        SaveFinancialRecurrenceRequest $request,
        int $recurrence,
    ): RedirectResponse {
        $this->recurrenceService->update(
            $this->findRecurrence($recurrence),
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Recorrência atualizada com sucesso.',
        ]);

        return to_route('recurrences.index');
    }

    public function toggleStatus(int $recurrence): RedirectResponse
    {
        $financialRecurrence = $this->recurrenceService->toggleActive(
            $this->findRecurrence($recurrence),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $financialRecurrence->is_active
                ? 'Recorrência ativada e compromissos futuros atualizados.'
                : 'Recorrência pausada com sucesso.',
        ]);

        return to_route('recurrences.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findRecurrence(int $recurrence): FinancialRecurrence
    {
        return $this->workspace()
            ->financialRecurrences()
            ->with([
                'account:id,name',
                'creditCard:id,name,last_four',
                'category:id,name,parent_id',
                'category.parent:id,name',
                'familyMember:id,name',
            ])
            ->withCount('transactions')
            ->findOrFail($recurrence);
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceOptions(): array
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
                ->with('parent:id,name')
                ->orderBy('type')
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
            'frequencyOptions' => RecurrenceFrequency::options(),
            'typeOptions' => [
                [
                    'value' => FinancialTransactionType::Expense->value,
                    'label' => FinancialTransactionType::Expense->label(),
                ],
                [
                    'value' => FinancialTransactionType::Income->value,
                    'label' => FinancialTransactionType::Income->label(),
                ],
            ],
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
    private function recurrenceData(FinancialRecurrence $recurrence): array
    {
        $categoryName = $recurrence->category?->name;

        if ($recurrence->category?->parent !== null) {
            $categoryName = "{$recurrence->category->parent->name} / {$recurrence->category->name}";
        }

        return [
            'id' => $recurrence->id,
            'type' => $recurrence->type->value,
            'type_label' => $recurrence->type->label(),
            'description' => $recurrence->description,
            'amount' => $recurrence->amount,
            'financial_account_id' => $recurrence->financial_account_id,
            'financial_account_name' => $recurrence->account?->name,
            'credit_card_id' => $recurrence->credit_card_id,
            'credit_card_name' => $recurrence->creditCard === null
                ? null
                : "{$recurrence->creditCard->name} · final {$recurrence->creditCard->last_four}",
            'category_id' => $recurrence->category_id,
            'category_name' => $categoryName,
            'family_member_id' => $recurrence->family_member_id,
            'family_member_name' => $recurrence->familyMember?->name,
            'payment_method' => $recurrence->payment_method->value,
            'payment_method_label' => $recurrence->payment_method->label(),
            'payee_name' => $recurrence->payee_name,
            'payment_instructions' => $recurrence->payment_instructions,
            'frequency' => $recurrence->frequency->value,
            'frequency_label' => $recurrence->frequency->label(),
            'interval' => $recurrence->interval,
            'schedule_label' => $this->scheduleLabel($recurrence),
            'starts_on' => $recurrence->starts_on->toDateString(),
            'ends_on' => $recurrence->ends_on?->toDateString(),
            'next_occurrence' => $this->recurrenceService
                ->nextOccurrence($recurrence)?->toDateString(),
            'is_active' => $recurrence->is_active,
            'generated_transactions_count' => (int) (
                $recurrence->getAttribute('transactions_count') ?? 0
            ),
            'notes' => $recurrence->notes,
        ];
    }

    private function scheduleLabel(FinancialRecurrence $recurrence): string
    {
        if ($recurrence->interval === 1) {
            return $recurrence->frequency->label();
        }

        $unit = match ($recurrence->frequency) {
            RecurrenceFrequency::Weekly => 'semanas',
            RecurrenceFrequency::Monthly => 'meses',
            RecurrenceFrequency::Yearly => 'anos',
        };

        return "A cada {$recurrence->interval} {$unit}";
    }
}
