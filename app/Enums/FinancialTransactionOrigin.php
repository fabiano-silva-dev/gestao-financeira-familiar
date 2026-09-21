<?php

namespace App\Enums;

enum FinancialTransactionOrigin: string
{
    case Manual = 'manual';
    case Recurrence = 'recurrence';
    case Ofx = 'ofx';
    case CardImport = 'card_import';
    case Api = 'api';
}
