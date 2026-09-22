<?php

namespace App\Enums;

enum FinancialImportType: string
{
    case Ofx = 'ofx';
    case CardStatement = 'card_statement';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Ofx => 'Extrato bancário',
            self::CardStatement => 'Fatura de cartão',
            self::Document => 'Documento a identificar',
        };
    }
}
