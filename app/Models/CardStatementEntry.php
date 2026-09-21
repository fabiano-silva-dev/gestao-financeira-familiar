<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $purchased_on
 * @property string $amount
 * @property int|null $installment_number
 * @property int|null $total_installments
 * @property array<string, string|null>|null $raw_data
 * @property bool $is_reconciled
 * @property-read CreditCard $creditCard
 * @property-read CreditCardInvoice $invoice
 */
#[Fillable([
    'workspace_id',
    'financial_import_id',
    'credit_card_id',
    'credit_card_invoice_id',
    'purchased_on',
    'description',
    'amount',
    'installment_number',
    'total_installments',
    'external_id',
    'deduplication_key',
    'raw_data',
    'is_reconciled',
])]
class CardStatementEntry extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<FinancialImport, $this> */
    public function financialImport(): BelongsTo
    {
        return $this->belongsTo(FinancialImport::class);
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return BelongsTo<CreditCardInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class, 'credit_card_invoice_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchased_on' => 'date',
            'amount' => 'decimal:2',
            'installment_number' => 'integer',
            'total_installments' => 'integer',
            'raw_data' => 'array',
            'is_reconciled' => 'boolean',
        ];
    }
}
