<?php

namespace App\Enums;

enum FinancialAccountType: string
{
    case Checking = 'checking';
    case Digital = 'digital';
    case Savings = 'savings';
    case Cash = 'cash';
    case Reserve = 'reserve';
    case Investment = 'investment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Checking => 'Conta corrente',
            self::Digital => 'Conta digital',
            self::Savings => 'Poupança',
            self::Cash => 'Carteira / dinheiro',
            self::Reserve => 'Reserva / Cofrinho',
            self::Investment => 'Conta de investimento',
            self::Other => 'Outra',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ],
            self::cases(),
        );
    }
}
