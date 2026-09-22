<?php

namespace App\Enums;

enum ExpenseRefundDestination: string
{
    case Account = 'account';
    case CreditCard = 'credit_card';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Conta financeira',
            self::CreditCard => 'Cartão de crédito',
        };
    }
}
