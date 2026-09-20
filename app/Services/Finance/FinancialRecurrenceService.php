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
    public const GENERATION_HORIZON_DAYS = 90;

    public function __construct(
        private readonly FinancialEntryService $entryService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Workspace $workspace, array $data): FinancialRecurrence
    {
        return DB::transaction(function () use ($workspace, $data): FinancialRecurrence {
            $today = CarbonImmutable::today();
            $startsOn = CarbonImmutable::parse((string) $data['starts_on']);
            $recurrence = $workspace->financialRecurrences()->create([
                ...$data,
                'generation_started_on' => $startsOn->greaterThan($today)
                    ? $startsOn->toDateString()
                    : $today->toDateString(),
                'is_active' => true,
            ]);

            $this->generate(
                $recurrence,
                $today->addDays(self::GENERATION_HORIZON_DAYS),
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
            $this->clearFuturePlannedOccurrences($recurrence);

            $today = CarbonImmutable::today();
            $startsOn = CarbonImmutable::parse((string) $data['starts_on']);

            $recurrence->update([
                ...$data,
                'generation_started_on' => $startsOn->greaterThan($today)
                    ? $startsOn->toDateString()
                    : $today->toDateString(),
            ]);

            if ($recurrence->is_active) {
                $this->generate(
                    $recurrence->refresh(),
                    $today->addDays(self::GENERATION_HORIZON_DAYS),
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
                $today = CarbonImmutable::today();
                $startsOn = CarbonImmutable::parse($recurrence->starts_on->toDateString());

                $recurrence->update([
                    'generation_started_on' => $startsOn->greaterThan($today)
                        ? $startsOn->toDateString()
                        : $today->toDateString(),
                ]);

                $this->generate(
                    $recurrence->refresh(),
                    $today->addDays(self::GENERATION_HORIZON_DAYS),
                );
            }

            return $recurrence->refresh();
        });
    }

    public function generateActive(?CarbonImmutable $through = null): int
    {
        $through ??= CarbonImmutable::today()->addDays(self::GENERATION_HORIZON_DAYS);
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

    public function generate(
        FinancialRecurrence $recurrence,
        CarbonImmutable $through,
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

        foreach ($this->occurrencesBetween($recurrence, $generationStart, $through) as $occurrence) {
            $occurrenceDate = $occurrence->toDateString();
            $usesCreditCard = $recurrence->type === FinancialTransactionType::Expense
                && $recurrence->payment_method === PaymentMethod::CreditCard;

            if ($usesCreditCard && $occurrence->greaterThan($today)) {
                continue;
            }

            $exists = $recurrence->transactions()
                ->whereDate('recurrence_occurrence_date', $occurrenceDate)
                ->exists();

            if ($exists) {
                continue;
            }

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
                    'settled_on' => null,
                    'status' => $usesCreditCard
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
        $projection = [];

        for ($index = 0; $index < $months; $index++) {
            $month = $firstMonth->addMonths($index);
            $projection[$month->format('Y-m')] = [
                'month' => $month->toDateString(),
                'income' => 0,
                'expenses' => 0,
            ];
        }

        $workspace->financialRecurrences()
            ->where('is_active', true)
            ->get()
            ->each(function (FinancialRecurrence $recurrence) use (
                $firstMonth,
                $lastMonth,
                &$projection,
            ): void {
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
                    $field = $recurrence->type === FinancialTransactionType::Income
                        ? 'income'
                        : 'expenses';

                    $projection[$key][$field] += $amount;
                }
            });

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

    private function clearFuturePlannedOccurrences(
        FinancialRecurrence $recurrence,
    ): void {
        $recurrence->transactions()
            ->where('status', FinancialTransactionStatus::Planned->value)
            ->whereDate('recurrence_occurrence_date', '>=', CarbonImmutable::today())
            ->whereDoesntHave('accountMovements')
            ->delete();
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
