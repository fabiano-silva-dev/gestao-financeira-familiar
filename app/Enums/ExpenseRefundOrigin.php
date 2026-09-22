<?php

namespace App\Enums;

enum ExpenseRefundOrigin: string
{
    case Manual = 'manual';
    case BankReconciliation = 'bank_reconciliation';
    case CardStatement = 'card_statement';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::BankReconciliation => 'Conciliação bancária',
            self::CardStatement => 'Fatura de cartão',
            self::Api => 'API',
        };
    }
}
