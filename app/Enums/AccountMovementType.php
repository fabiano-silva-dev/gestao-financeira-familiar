<?php

namespace App\Enums;

enum AccountMovementType: string
{
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case ExpensePayment = 'expense_payment';
    case IncomeReceipt = 'income_receipt';
    case CardPayment = 'card_payment';
    case Refund = 'refund';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::TransferOut => 'Saída de transferência',
            self::TransferIn => 'Entrada de transferência',
            self::ExpensePayment => 'Pagamento de despesa',
            self::IncomeReceipt => 'Recebimento de receita',
            self::CardPayment => 'Pagamento de fatura',
            self::Refund => 'Reembolso',
            self::Adjustment => 'Ajuste de conta',
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
