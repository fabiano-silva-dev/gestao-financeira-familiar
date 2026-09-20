<?php

namespace App\Enums;

enum TransactionInstallmentStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em aberto',
            self::Paid => 'Paga',
            self::Cancelled => 'Cancelada',
        };
    }
}
