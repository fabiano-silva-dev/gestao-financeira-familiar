<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancialRecurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateFinancialRecurrences extends Command
{
    protected $signature = 'finance:generate-recurrences
        {--through= : Data limite para geração no formato YYYY-MM-DD}';

    protected $description = 'Gera lançamentos futuros das recorrências financeiras ativas';

    public function handle(FinancialRecurrenceService $recurrenceService): int
    {
        $through = $this->option('through');

        $generated = $recurrenceService->generateActive(
            is_string($through) && $through !== ''
                ? CarbonImmutable::parse($through)
                : null,
        );

        $this->info("{$generated} lançamento(s) recorrente(s) gerado(s).");

        return self::SUCCESS;
    }
}
