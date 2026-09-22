<?php

namespace App\Enums;

enum FinancialImportType: string
{
    case Ofx = 'ofx';
    case CardStatement = 'card_statement';

    public function label(): string
    {
        return match ($this) {
            self::Ofx => 'Extrato bancário',
            self::CardStatement => 'Fatura de cartão',
        };
    }
}
