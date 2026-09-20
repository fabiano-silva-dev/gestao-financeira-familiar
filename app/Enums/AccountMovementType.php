<?php

namespace App\Enums;

enum AccountMovementType: string
{
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case ExpensePayment = 'expense_payment';
    case IncomeReceipt = 'income_receipt';
    case CardPayment = 'card_payment';
    case Adjustment = 'adjustment';
}
