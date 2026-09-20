<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Pix = 'pix';
    case Boleto = 'boleto';
    case BankTransfer = 'bank_transfer';
    case AutomaticDebit = 'automatic_debit';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case Cash = 'cash';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'PIX',
            self::Boleto => 'Boleto',
            self::BankTransfer => 'Transferência bancária',
            self::AutomaticDebit => 'Débito automático',
            self::DebitCard => 'Cartão de débito',
            self::CreditCard => 'Cartão de crédito',
            self::Cash => 'Dinheiro',
            self::Other => 'Outra',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return self::mapOptions(self::cases());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function invoiceOptions(): array
    {
        return self::mapOptions([
            self::Boleto,
            self::Pix,
            self::AutomaticDebit,
            self::BankTransfer,
            self::Other,
        ]);
    }

    /**
     * @param  array<int, self>  $methods
     * @return array<int, array{value: string, label: string}>
     */
    private static function mapOptions(array $methods): array
    {
        return array_map(
            fn (self $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
            ],
            $methods,
        );
    }
}
