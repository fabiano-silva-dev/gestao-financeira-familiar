<?php

namespace App\Enums;

enum CreditCardInvoiceStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::Closed => 'Fechada',
            self::Partial => 'Parcialmente paga',
            self::Paid => 'Paga',
        };
    }
}
