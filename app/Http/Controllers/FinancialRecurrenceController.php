<?php

namespace App\Http\Controllers;

use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\RecurrenceFrequency;
use App\Http\Requests\SaveFinancialRecurrenceRequest;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Models\FinancialRecurrence;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use App\Services\Finance\FinancialRecurrenceService;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialRecurrenceController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly FinancialRecurrenceService $recurrenceService,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $listing = ListingQuery::from(
            $request,
            ['description', 'frequency', 'next', 'category', 'amount', 'status'],
            'status',
            'desc',
            ['type', 'status', 'frequency'],
        );
        $period = $request->string('period')->toString();

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            $period = CarbonImmutable::today()->format('Y-m');
        }

        $currentPeriod = CarbonImmutable::parse("{$period}-01");
        $periodStart = $currentPeriod->startOfMonth();
        $periodEnd = $currentPeriod->endOfMonth();
        $query = $workspace->financialRecurrences()
            ->with([
                'account:id,name',
                'creditCard:id,name,last_four',
                'category:id,name,parent_id',
                'category.parent:id,name',
                'familyMember:id,name',
                'transactions' => fn ($transactionQuery) => $transactionQuery
                    ->whereBetween('recurrence_occurrence_date', [
                        $periodStart->toDateString(),
                        $periodEnd->toDateString(),
                    ])
                    ->orderBy('recurrence_occurrence_date'),
            ])
            ->whereHas(
                'transactions',
                fn ($transactionQuery) => $transactionQuery
                    ->whereBetween('recurrence_occurrence_date', [
                        $periodStart->toDateString(),
                        $periodEnd->toDateString(),
                    ]),
            )
            ->withCount('transactions')
            ->select('financial_recurrences.*');
        $listing->applySearch($query, ['description', 'payee_name']);

        $type = $listing->filter('type');

        if ($type !== null && FinancialTransactionType::tryFrom($type) !== null) {
            $query->where('type', $type);
        }

        $active = $listing->booleanFilter('status');

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        $frequency = $listing->filter('frequency');

        if ($frequency !== null && RecurrenceFrequency::tryFrom($frequency) !== null) {
            $query->where('frequency', $frequency);
        }

        if ($listing->sort === 'status') {
            $query->orderBy('is_active', $listing->direction)->orderBy('description');
        } else {
            $listing->applySort($query, [
                'description' => 'description',
                'frequency' => 'frequency',
                'category' => function (Builder $query, string $direction): void {
                    $query->leftJoin(
                        'categories',
                        'categories.id',
                        '=',
                        'financial_recurrences.category_id',
                    )->orderBy('categories.name', $direction);
                },
                'amount' => 'amount',
                'status' => 'is_active',
            ], 'financial_recurrences.id');
        }

        $recurrences = $query
            ->get()
            ->map(fn (FinancialRecurrence $recurrence): array => $this->periodRecurrenceData(
                $recurrence,
            ));

        if ($listing->sort === 'next') {
            $recurrences = $listing->sortMapped($recurrences, [
                'next' => fn (array $recurrence): string => $recurrence['period_occurrence'] ?? '',
            ]);
        }

        return Inertia::render('recurrences/index', [
            'recurrences' => $recurrences,
            'projection' => $this->recurrenceService->monthlyProjection(
                $workspace,
                $currentPeriod,
            ),
            'currentPeriod' => $currentPeriod->toDateString(),
            'filters' => [
                ...$listing->toArray(),
                'period' => $period,
            ],
            'hasRecords' => $workspace->financialRecurrences()->exists(),
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
            'statusOptions' => ListingQuery::statusOptions(),
            'frequencyOptions' => RecurrenceFrequency::options(),
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
        $financialRecurrence = $this->findRecurrence($recurrence, withTrashed: true);

        if ($financialRecurrence->trashed()) {
            return Inertia::render('recurrences/archived', [
                'description' => $financialRecurrence->description,
            ]);
        }

        return Inertia::render('recurrences/edit', [
            'recurrence' => $this->recurrenceData($financialRecurrence),
            'occurrences' => $this->occurrenceData($financialRecurrence),
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

    public function destroy(int $recurrence): RedirectResponse
    {
        $this->recurrenceService->archive(
            $this->findRecurrence($recurrence),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Recorrência excluída. Lançamentos realizados e históricos foram preservados.',
        ]);

        return to_route('recurrences.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findRecurrence(
        int $recurrence,
        bool $withTrashed = false,
    ): FinancialRecurrence {
        $query = $this->workspace()->financialRecurrences();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query
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
     * @return array<int, array<string, mixed>>
     */
    private function occurrenceData(FinancialRecurrence $recurrence): array
    {
        return $recurrence->transactions()
            ->with([
                'account:id,name',
                'creditCard:id,name,last_four',
                'accountMovements:id,financial_transaction_id,is_reconciled',
            ])
            ->orderByDesc('recurrence_occurrence_date')
            ->get()
            ->map(fn (FinancialTransaction $transaction): array => [
                'id' => $transaction->id,
                'occurrence_date' => $transaction->recurrence_occurrence_date?->toDateString()
                    ?? $transaction->transaction_date->toDateString(),
                'transaction_date' => $transaction->transaction_date->toDateString(),
                'due_date' => $transaction->due_date?->toDateString(),
                'settled_on' => $transaction->settled_on?->toDateString(),
                'amount' => $transaction->amount,
                'status' => $transaction->status->value,
                'status_label' => $this->occurrenceStatusLabel($transaction),
                'account_name' => $transaction->account?->name
                    ?? ($transaction->creditCard === null
                        ? null
                        : "{$transaction->creditCard->name} · final {$transaction->creditCard->last_four}"),
                'is_overridden' => $transaction->recurrence_is_overridden,
                'is_reconciled' => $transaction->accountMovements
                    ->contains(fn ($movement): bool => (bool) $movement->is_reconciled),
            ])
            ->all();
    }

    private function occurrenceStatusLabel(FinancialTransaction $transaction): string
    {
        if ($transaction->status === FinancialTransactionStatus::Cancelled) {
            return 'Cancelado';
        }

        if ($transaction->settled_on !== null) {
            return $transaction->type === FinancialTransactionType::Expense
                ? 'Pago'
                : 'Recebido';
        }

        if ($transaction->status === FinancialTransactionStatus::Planned) {
            return $transaction->type === FinancialTransactionType::Expense
                ? 'A pagar'
                : 'A receber';
        }

        return 'Confirmado';
    }

    /**
     * @return array<string, mixed>
     */
    private function periodRecurrenceData(FinancialRecurrence $recurrence): array
    {
        $data = $this->recurrenceData($recurrence);
        $occurrences = $recurrence->transactions
            ->reject(
                fn (FinancialTransaction $transaction): bool => $transaction->status
                    === FinancialTransactionStatus::Cancelled,
            )
            ->sortBy(
                fn (FinancialTransaction $transaction): string => (
                    $transaction->recurrence_occurrence_date
                    ?? $transaction->transaction_date
                )->toDateString(),
            )
            ->values();

        if ($occurrences->isEmpty()) {
            return [
                ...$data,
                'period_occurrence' => null,
                'period_status' => 'cancelled',
                'period_status_label' => 'Cancelada',
            ];
        }

        $pending = $occurrences->filter(
            fn (FinancialTransaction $transaction): bool => $transaction->settled_on === null,
        );
        $today = CarbonImmutable::today()->toDateString();
        $hasOverdue = $pending->contains(
            function (FinancialTransaction $transaction) use ($today): bool {
                $dueOn = $transaction->due_date
                    ?? $transaction->recurrence_occurrence_date
                    ?? $transaction->transaction_date;

                return $dueOn->toDateString() < $today;
            },
        );

        if ($hasOverdue) {
            $status = 'overdue';
            $statusLabel = 'Vencida';
        } elseif ($pending->isEmpty()) {
            $status = 'paid';
            $statusLabel = $recurrence->type === FinancialTransactionType::Expense
                ? 'Paga'
                : 'Recebida';
        } else {
            $status = 'pending';
            $statusLabel = 'Pendente';
        }

        /** @var FinancialTransaction $firstOccurrence */
        $firstOccurrence = $occurrences->first();
        $periodOccurrence = $firstOccurrence->recurrence_occurrence_date
            ?? $firstOccurrence->transaction_date;

        return [
            ...$data,
            'period_occurrence' => $periodOccurrence->toDateString(),
            'period_status' => $status,
            'period_status_label' => $statusLabel,
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
            'generation_started_on' => $recurrence->generation_started_on->toDateString(),
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
