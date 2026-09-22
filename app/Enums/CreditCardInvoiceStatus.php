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

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function filterOptions(): array
    {
        return [
            ...self::options(),
            ['value' => 'overdue', 'label' => 'Vencida'],
        ];
    }
}
