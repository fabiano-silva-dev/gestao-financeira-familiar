<?php

namespace App\Enums;

enum FinancialImportStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Processing => 'Processando',
            self::Completed => 'Concluída',
            self::Failed => 'Falhou',
        };
    }
}
