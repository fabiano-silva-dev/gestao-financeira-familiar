<?php

namespace App\Enums;

enum FinancialTransactionStatus: string
{
    case Planned = 'planned';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planejada',
            self::Confirmed => 'Confirmada',
            self::Cancelled => 'Cancelada',
        };
    }
}
