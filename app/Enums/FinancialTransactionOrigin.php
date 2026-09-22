<?php

namespace App\Enums;

enum FinancialTransactionOrigin: string
{
    case Manual = 'manual';
    case Recurrence = 'recurrence';
    case Ofx = 'ofx';
    case CardImport = 'card_import';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Lançamento manual',
            self::Recurrence => 'Recorrência',
            self::Ofx => 'Extrato bancário',
            self::CardImport => 'Fatura de cartão',
            self::Api => 'API',
        };
    }

    public function sourceKind(): string
    {
        return match ($this) {
            self::Ofx => 'bank_statement',
            self::CardImport => 'card_statement',
            default => $this->value,
        };
    }
}
