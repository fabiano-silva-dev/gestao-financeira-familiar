<?php

namespace App\Services\Finance;

use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\RecurrenceFrequency;
use App\Models\FinancialRecurrence;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinancialRecurrenceService
{
    public const GENERATION_HORIZON_MONTHS = 12;

    public function __construct(
        private readonly FinancialEntryService $entryService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Workspace $workspace, array $data): FinancialRecurrence
    {
        return DB::transaction(function () use ($workspace, $data): FinancialRecurrence {
            [$data, $alreadySettled] = $this->extractSettlementFlag($data);
            $today = CarbonImmutable::today();
            $recurrence = $workspace->financialRecurrences()->create([
                ...$data,
                'generation_started_on' => $this->resolveGenerationStartedOn($data, $today),
                'is_active' => true,
            ]);

            $this->prepareCurrentDueGeneration($recurrence, $alreadySettled, $today);
            $this->generate(
                $recurrence->refresh(),
                $today->addMonths(self::GENERATION_HORIZON_MONTHS),
                $alreadySettled,
            );

            return $recurrence->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        FinancialRecurrence $recurrence,
        array $data,
    ): FinancialRecurrence {
        return DB::transaction(function () use ($recurrence, $data): FinancialRecurrence {
            [$data, $alreadySettled] = $this->extractSettlementFlag($data);
            $this->clearFuturePlannedOccurrences($recurrence);

            $today = CarbonImmutable::today();
            $generationStartedOn = $this->resolveGenerationStartedOn($data, $today);

            $recurrence->update([
                ...$data,
                'generation_started_on' => $generationStartedOn,
            ]);

            if ($recurrence->is_active) {
                $this->clearPlannedOccurrencesBefore(
                    $recurrence->refresh(),
                    CarbonImmutable::parse($generationStartedOn),
                );
                $this->prepareCurrentDueGeneration(
                    $recurrence->refresh(),
                    $alreadySettled,
                    $today,
                );
                $this->generate(
                    $recurrence->refresh(),
                    $today->addMonths(self::GENERATION_HORIZON_MONTHS),
                    $alreadySettled,
                );
                $this->settleCurrentDueOccurrence(
                    $recurrence->refresh(),
                    $alreadySettled,
                );
            }

            return $recurrence->refresh();
        });
    }

    public function toggleActive(FinancialRecurrence $recurrence): FinancialRecurrence
    {
        return DB::transaction(function () use ($recurrence): FinancialRecurrence {
            $isActive = ! $recurrence->is_active;

            if (! $isActive) {
                $this->clearFuturePlannedOccurrences($recurrence);
            }

            $recurrence->update(['is_active' => $isActive]);
            $recurrence->refresh();

            if ($isActive) {
                $startsOn = CarbonImmutable::parse($recurrence->starts_on->toDateString());
                $generationStart = CarbonImmutable::parse(
                    $recurrence->generation_started_on->toDateString(),
                );

                if ($generationStart->lessThan($startsOn)) {
                    $recurrence->update([
                        'generation_started_on' => $startsOn->toDateString(),
                    ]);
                }

                $today = CarbonImmutable::today();
                $this->generate(
                    $recurrence->refresh(),
                    $today->addMonths(self::GENERATION_HORIZON_MONTHS),
                );
            }

            return $recurrence->refresh();
        });
    }

    public function generateActive(?CarbonImmutable $through = null): int
    {
        $through ??= CarbonImmutable::today()->addMonths(self::GENERATION_HORIZON_MONTHS);
        $generated = 0;

        FinancialRecurrence::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (FinancialRecurrence $recurrence) use (
                $through,
                &$generated,
            ): void {
                $generated += $this->generate($recurrence, $through);
            });

        return $generated;
    }

    public function generateForWorkspace(
        Workspace $workspace,
        ?CarbonImmutable $through = null,
    ): int {
        $through ??= CarbonImmutable::today()->addMonths(self::GENERATION_HORIZON_MONTHS);
        $generated = 0;

        $workspace->financialRecurrences()
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (FinancialRecurrence $recurrence) use (
                $through,
                &$generated,
            ): void {
                $generated += $this->generate($recurrence, $through);
            });

        return $generated;
    }

    public function generate(
        FinancialRecurrence $recurrence,
        CarbonImmutable $through,
        bool $settleCurrentDue = false,
    ): int {
        if (! $recurrence->is_active) {
            return 0;
        }

        $generationStart = CarbonImmutable::parse(
            $recurrence->generation_started_on->toDateString(),
        );
        $generated = 0;
        $workspace = $recurrence->workspace()->firstOrFail();
        $today = CarbonImmutable::today();
        $currentDue = $settleCurrentDue
            ? $this->currentDueOccurrence($recurrence, $today)
            : null;

        foreach ($this->occurrencesBetween($recurrence, $generationStart, $through) as $occurrence) {
            $occurrenceDate = $occurrence->toDateString();
            $usesCreditCard = $recurrence->type === FinancialTransactionType::Expense
                && $recurrence->payment_method === PaymentMethod::CreditCard;

            $exists = $recurrence->transactions()
                ->whereDate('recurrence_occurrence_date', $occurrenceDate)
                ->exists();

            if ($exists) {
                continue;
            }

            $shouldSettle = $currentDue !== null
                && ! $usesCreditCard
                && $occurrence->equalTo($currentDue);

            $this->entryService->create(
                $workspace,
                [
                    'type' => $recurrence->type->value,
                    'transaction_date' => $occurrenceDate,
                    'competence_date' => $occurrenceDate,
                    'description' => $recurrence->description,
                    'amount' => $recurrence->amount,
                    'financial_account_id' => $usesCreditCard
                        ? null
                        : $recurrence->financial_account_id,
                    'credit_card_id' => $usesCreditCard
                        ? $recurrence->credit_card_id
                        : null,
                    'category_id' => $recurrence->category_id,
                    'family_member_id' => $recurrence->family_member_id,
                    'payment_method' => $recurrence->payment_method->value,
                    'payee_name' => $recurrence->payee_name,
                    'payment_instructions' => $recurrence->payment_instructions,
                    'due_date' => $usesCreditCard ? null : $occurrenceDate,
                    'settled_on' => $shouldSettle
                        ? $this->settledOnForOccurrence($occurrence, $today)
                        : null,
                    'status' => $shouldSettle
                        ? FinancialTransactionStatus::Confirmed->value
                        : FinancialTransactionStatus::Planned->value,
                    'installment_count' => $usesCreditCard ? 1 : null,
                    'financial_recurrence_id' => $recurrence->id,
                    'recurrence_occurrence_date' => $occurrenceDate,
                    'notes' => $recurrence->notes,
                ],
                FinancialTransactionOrigin::Recurrence,
            );

            $generated++;
        }

        return $generated;
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    public function occurrencesBetween(
        FinancialRecurrence $recurrence,
        CarbonImmutable $from,
        CarbonImmutable $through,
    ): array {
        $start = CarbonImmutable::parse($recurrence->starts_on->toDateString());
        $recurrenceEnd = $recurrence->ends_on === null
            ? null
            : CarbonImmutable::parse($recurrence->ends_on->toDateString());
        $end = $recurrenceEnd !== null && $recurrenceEnd->lessThan($through)
            ? $recurrenceEnd
            : $through;

        if ($end->lessThan($start) || $end->lessThan($from)) {
            return [];
        }

        $occurrences = [];

        for ($index = 0; $index < 1000; $index++) {
            $occurrence = $this->occurrenceAt($recurrence, $start, $index);

            if ($occurrence->greaterThan($end)) {
                break;
            }

            if (! $occurrence->lessThan($from)) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
    }

    public function nextOccurrence(
        FinancialRecurrence $recurrence,
        ?CarbonImmutable $from = null,
    ): ?CarbonImmutable {
        $from ??= CarbonImmutable::today();
        $occurrences = $this->occurrencesBetween(
            $recurrence,
            $from,
            $from->addYears(5),
        );

        return $occurrences[0] ?? null;
    }

    /**
     * @return array<int, array{month: string, income: string, expenses: string, net: string}>
     */
    public function monthlyProjection(
        Workspace $workspace,
        CarbonImmutable $start,
        int $months = 6,
    ): array {
        $firstMonth = $start->startOfMonth();
        $lastMonth = $firstMonth->addMonths($months - 1)->endOfMonth();
        $projection = $this->initializeProjection($firstMonth, $months);

        $recurrences = $workspace->financialRecurrences()
            ->where('is_active', true)
            ->get();

        foreach ($recurrences as $recurrence) {
            $amount = $this->moneyToCents((string) $recurrence->amount);

            $generationStart = CarbonImmutable::parse(
                $recurrence->generation_started_on->toDateString(),
            );
            $projectionStart = $generationStart->greaterThan($firstMonth)
                ? $generationStart
                : $firstMonth;

            foreach ($this->occurrencesBetween(
                $recurrence,
                $projectionStart,
                $lastMonth,
            ) as $occurrence) {
                $key = $occurrence->format('Y-m');

                if ($recurrence->type === FinancialTransactionType::Income) {
                    $projection[$key]['income'] += $amount;
                } else {
                    $projection[$key]['expenses'] += $amount;
                }
            }
        }

        $result = [];

        foreach ($projection as $month) {
            $result[] = [
                'month' => $month['month'],
                'income' => $this->money($month['income']),
                'expenses' => $this->money($month['expenses']),
                'net' => $this->money($month['income'] - $month['expenses']),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{month: string, income: int, expenses: int}>
     */
    private function initializeProjection(
        CarbonImmutable $firstMonth,
        int $months,
    ): array {
        $projection = [];

        for ($index = 0; $index < $months; $index++) {
            $month = $firstMonth->addMonths($index);
            $projection[$month->format('Y-m')] = [
                'month' => $month->toDateString(),
                'income' => 0,
                'expenses' => 0,
            ];
        }

        return $projection;
    }

    private function resolveGenerationStartedOn(
        array $data,
        CarbonImmutable $today,
    ): string {
        $startsOn = CarbonImmutable::parse((string) $data['starts_on']);
        $requested = $data['generation_started_on'] ?? null;

        if (! is_string($requested) || $requested === '') {
            return $startsOn->greaterThan($today)
                ? $startsOn->toDateString()
                : $today->toDateString();
        }

        $generationStart = CarbonImmutable::parse($requested);

        if ($generationStart->lessThan($startsOn)) {
            return $startsOn->toDateString();
        }

        return $generationStart->toDateString();
    }

    private function clearPlannedOccurrencesBefore(
        FinancialRecurrence $recurrence,
        CarbonImmutable $generationStart,
    ): void {
        $recurrence->transactions()
            ->where('status', FinancialTransactionStatus::Planned->value)
            ->where('recurrence_is_overridden', false)
            ->whereNull('settled_on')
            ->whereDate('recurrence_occurrence_date', '<', $generationStart->toDateString())
            ->whereDoesntHave('accountMovements')
            ->delete();
    }

    private function clearFuturePlannedOccurrences(
        FinancialRecurrence $recurrence,
    ): void {
        $recurrence->transactions()
            ->where('status', FinancialTransactionStatus::Planned->value)
            ->where('recurrence_is_overridden', false)
            ->whereDate('recurrence_occurrence_date', '>=', CarbonImmutable::today())
            ->whereDoesntHave('accountMovements')
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function extractSettlementFlag(array $data): array
    {
        $raw = $data['already_settled'] ?? false;
        unset($data['already_settled']);

        return [$data, filter_var($raw, FILTER_VALIDATE_BOOLEAN)];
    }

    private function prepareCurrentDueGeneration(
        FinancialRecurrence $recurrence,
        bool $alreadySettled,
        CarbonImmutable $today,
    ): void {
        if (! $alreadySettled || $this->usesCreditCard($recurrence)) {
            return;
        }

        $currentDue = $this->currentDueOccurrence($recurrence, $today);

        if ($currentDue === null) {
            return;
        }

        $generationStart = CarbonImmutable::parse(
            $recurrence->generation_started_on->toDateString(),
        );

        if ($currentDue->lessThan($generationStart)) {
            $recurrence->update([
                'generation_started_on' => $currentDue->toDateString(),
            ]);
        }
    }

    private function settleCurrentDueOccurrence(
        FinancialRecurrence $recurrence,
        bool $alreadySettled,
    ): void {
        if (! $alreadySettled || $this->usesCreditCard($recurrence)) {
            return;
        }

        $today = CarbonImmutable::today();
        $currentDue = $this->currentDueOccurrence($recurrence, $today);

        if ($currentDue === null) {
            return;
        }

        $entry = $recurrence->transactions()
            ->whereDate('recurrence_occurrence_date', $currentDue->toDateString())
            ->where('status', FinancialTransactionStatus::Planned->value)
            ->where('recurrence_is_overridden', false)
            ->whereNull('settled_on')
            ->first();

        if ($entry === null) {
            return;
        }

        $this->entryService->update($entry, [
            'type' => $entry->type->value,
            'transaction_date' => $entry->transaction_date->toDateString(),
            'competence_date' => $entry->competence_date?->toDateString()
                ?? $entry->transaction_date->toDateString(),
            'description' => $entry->description,
            'amount' => $entry->amount,
            'financial_account_id' => $entry->financial_account_id,
            'credit_card_id' => $entry->credit_card_id,
            'category_id' => $entry->category_id,
            'family_member_id' => $entry->family_member_id,
            'payment_method' => $entry->payment_method?->value,
            'payee_name' => $entry->payee_name,
            'payment_instructions' => $entry->payment_instructions,
            'due_date' => $entry->due_date?->toDateString(),
            'settled_on' => $this->settledOnForOccurrence($currentDue, $today),
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => $entry->notes,
        ]);
    }

    private function currentDueOccurrence(
        FinancialRecurrence $recurrence,
        CarbonImmutable $today,
    ): ?CarbonImmutable {
        $startsOn = CarbonImmutable::parse($recurrence->starts_on->toDateString());

        if ($startsOn->greaterThan($today)) {
            return $startsOn;
        }

        $past = $this->occurrencesBetween($recurrence, $startsOn, $today);

        return $past === [] ? $startsOn : $past[array_key_last($past)];
    }

    private function settledOnForOccurrence(
        CarbonImmutable $occurrence,
        CarbonImmutable $today,
    ): string {
        return $occurrence->greaterThan($today)
            ? $today->toDateString()
            : $occurrence->toDateString();
    }

    private function usesCreditCard(FinancialRecurrence $recurrence): bool
    {
        return $recurrence->type === FinancialTransactionType::Expense
            && $recurrence->payment_method === PaymentMethod::CreditCard;
    }

    private function occurrenceAt(
        FinancialRecurrence $recurrence,
        CarbonImmutable $start,
        int $index,
    ): CarbonImmutable {
        $interval = max(1, $recurrence->interval);

        return match ($recurrence->frequency) {
            RecurrenceFrequency::Weekly => $start->addWeeks($index * $interval),
            RecurrenceFrequency::Monthly => $this->monthlyOccurrence(
                $start,
                $index * $interval,
            ),
            RecurrenceFrequency::Yearly => $this->yearlyOccurrence(
                $start,
                $index * $interval,
            ),
        };
    }

    private function monthlyOccurrence(
        CarbonImmutable $start,
        int $months,
    ): CarbonImmutable {
        $month = $start->startOfMonth()->addMonths($months);

        return $month->day(min($start->day, $month->daysInMonth));
    }

    private function yearlyOccurrence(
        CarbonImmutable $start,
        int $years,
    ): CarbonImmutable {
        $year = $start->year + $years;
        $month = CarbonImmutable::create($year, $start->month, 1);

        return $month->day(min($start->day, $month->daysInMonth));
    }

    private function moneyToCents(string $amount): int
    {
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');

        return ((int) $whole * 100) + (int) $decimal;
    }

    private function money(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $formatted = sprintf(
            '%d.%02d',
            intdiv($absolute, 100),
            $absolute % 100,
        );

        return $negative ? '-'.$formatted : $formatted;
    }
}
