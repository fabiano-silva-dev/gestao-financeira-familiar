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

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            self::cases(),
        );
    }
}
