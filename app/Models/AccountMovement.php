<?php

namespace App\Models;

use App\Enums\AccountMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $occurred_on
 * @property string $amount
 * @property AccountMovementType $type
 * @property bool $is_reconciled
 */
#[Fillable([
    'workspace_id',
    'financial_transaction_id',
    'credit_card_invoice_payment_id',
    'financial_account_id',
    'occurred_on',
    'description',
    'amount',
    'type',
    'is_reconciled',
])]
class AccountMovement extends Model
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<FinancialTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    /**
     * @return BelongsTo<CreditCardInvoicePayment, $this>
     */
    public function invoicePayment(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoicePayment::class, 'credit_card_invoice_payment_id');
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'amount' => 'decimal:2',
            'type' => AccountMovementType::class,
            'is_reconciled' => 'boolean',
        ];
    }
}
