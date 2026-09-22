<?php

namespace App\Services\Reconciliation;

use App\Enums\AccountMovementType;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Services\Finance\CreditCardInvoiceService;
use Illuminate\Support\Collection;

final class InvoicePaymentSuggestionService
{
    public function __construct(
        private readonly ImportedMovementInterpreter $interpreter,
        private readonly CreditCardInvoiceService $invoiceService,
    ) {}

    /**
     * @param  Collection<int, CreditCardInvoice>  $invoices
     * @param  array<int, int>  $claimedInvoiceIds
     * @return array<int, array<string, mixed>>
     */
    public function candidates(
        BankStatementEntry $entry,
        Collection $invoices,
        array $claimedInvoiceIds = [],
    ): array {
        if (! $this->interpreter->isOutflow($entry->amount)) {
            return [];
        }

        $paymentCents = abs($this->interpreter->moneyToCents($entry->amount));
        $haystack = $this->interpreter->normalize(
            $entry->description.' '.($entry->memo ?? ''),
        );

        return $invoices
            ->filter(function (CreditCardInvoice $invoice) use ($claimedInvoiceIds, $paymentCents, $haystack, $entry): bool {
                if (in_array($invoice->id, $claimedInvoiceIds, true)) {
                    return false;
                }

                $outstandingCents = $this->invoiceService->outstandingCents($invoice);

                if ($paymentCents <= 0 || $paymentCents > $outstandingCents) {
                    return false;
                }

                $dateDistance = $this->dueDateDistance($entry, $invoice);
                $mentionsCard = $this->mentionsCard($haystack, $invoice->creditCard);
                $looksLikePayment = $this->interpreter->isInvoicePayment(
                    $entry->description,
                    $this->cardTokens($invoice->creditCard),
                );

                if ($paymentCents === $outstandingCents) {
                    return $dateDistance <= 45;
                }

                return $dateDistance <= 45 && ($mentionsCard || $looksLikePayment);
            })
            ->map(function (CreditCardInvoice $invoice) use ($entry, $haystack, $paymentCents): array {
                $details = $this->details($invoice);
                $dateDistance = $this->dueDateDistance($entry, $invoice);
                $mentionsCard = $this->mentionsCard($haystack, $invoice->creditCard);
                $looksLikePayment = $this->interpreter->isInvoicePayment(
                    $entry->description,
                    $this->cardTokens($invoice->creditCard),
                );
                $outstandingCents = $this->invoiceService->outstandingCents($invoice);
                $exactAmount = $paymentCents === $outstandingCents;
                $sameAccount = $invoice->creditCard->payment_account_id === $entry->financial_account_id;
                $dateScore = match (true) {
                    $dateDistance === 0 => 25,
                    $dateDistance === 1 => 20,
                    $dateDistance <= 3 => 14,
                    $dateDistance <= 7 => 8,
                    $dateDistance <= 15 => 4,
                    default => 0,
                };
                $score = min(100, 40
                    + ($exactAmount ? 30 : 10)
                    + $dateScore
                    + ($mentionsCard ? 20 : 0)
                    + ($looksLikePayment ? 10 : 0)
                    + ($sameAccount ? 8 : 0));
                [$confidence, $confidenceLabel] = match (true) {
                    $score >= 85 => ['high', 'Alta confiança'],
                    $score >= 72 => ['medium', 'Média confiança'],
                    default => ['low', 'Conferência manual'],
                };

                return [
                    ...$details,
                    'movement_id' => null,
                    'occurred_on' => $invoice->due_date->toDateString(),
                    'description' => $details['related_description'],
                    'amount' => $entry->amount,
                    'type' => AccountMovementType::CardPayment->value,
                    'type_label' => AccountMovementType::CardPayment->label(),
                    'score' => $score,
                    'confidence' => $confidence,
                    'confidence_label' => $confidenceLabel,
                    'date_distance' => $dateDistance,
                    'is_suggestion' => $score >= 72,
                ];
            })
            ->sort(function (array $left, array $right): int {
                return [$right['score'], $left['date_distance'], $right['invoice_id']]
                    <=> [$left['score'], $right['date_distance'], $left['invoice_id']];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function fromMovement(?AccountMovement $movement): array
    {
        $payment = $movement?->invoicePayment;
        $invoice = $payment?->invoice;

        if ($invoice === null) {
            return $this->emptyDetails();
        }

        $details = $this->details($invoice, alreadyRegistered: true);
        $details['invoice_payment_id'] = $payment->id;

        return [
            ...$details,
            'related_type' => AccountMovementType::CardPayment->value,
            'related_type_label' => AccountMovementType::CardPayment->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(CreditCardInvoice $invoice, bool $alreadyRegistered = false): array
    {
        $card = $invoice->creditCard;
        $month = $this->monthLabel($invoice->reference_month);
        $total = $this->invoiceService->totalAmount($invoice);
        $label = "Fatura {$card->name} {$month}";
        $prefix = $alreadyRegistered
            ? 'Pagamento já registrado da'
            : 'Possível pagamento da';

        return [
            'invoice_id' => $invoice->id,
            'invoice_payment_id' => null,
            'card_name' => $card->name,
            'card_last_four' => $card->last_four,
            'invoice_label' => $label,
            'invoice_due_date' => $invoice->due_date->toDateString(),
            'invoice_total_amount' => $total,
            'invoice_paid_amount' => (string) $invoice->paid_amount,
            'invoice_outstanding_amount' => $this->invoiceService->outstandingAmount($invoice),
            'invoice_status' => $invoice->status->value,
            'invoice_status_label' => $invoice->status->label(),
            'is_invoice_payment' => true,
            'related_is_transfer' => false,
            'related_type' => AccountMovementType::CardPayment->value,
            'related_type_label' => AccountMovementType::CardPayment->label(),
            'related_description' => "{$prefix} {$label} — ".$this->formatMoney($total),
            'related_account_name' => $card->name,
            'related_competence_date' => $invoice->reference_month->toDateString(),
            'related_payee_name' => $card->name,
            'related_category_id' => null,
            'related_category_name' => null,
            'related_parent_category_id' => null,
            'related_parent_category_name' => null,
            'related_subcategory_id' => null,
            'related_subcategory_name' => null,
            'related_transaction_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyDetails(): array
    {
        return [
            'invoice_id' => null,
            'invoice_payment_id' => null,
            'card_name' => null,
            'card_last_four' => null,
            'invoice_label' => null,
            'invoice_due_date' => null,
            'invoice_total_amount' => null,
            'invoice_paid_amount' => null,
            'invoice_outstanding_amount' => null,
            'invoice_status' => null,
            'invoice_status_label' => null,
            'is_invoice_payment' => false,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function cardTokens(CreditCard $card): array
    {
        return array_values(array_filter([
            $card->name,
            $card->institution,
        ], fn (?string $token): bool => is_string($token) && trim($token) !== ''));
    }

    private function dueDateDistance(BankStatementEntry $entry, CreditCardInvoice $invoice): int
    {
        return (int) abs($entry->occurred_on->diffInDays($invoice->due_date, false));
    }

    private function mentionsCard(string $haystack, CreditCard $card): bool
    {
        foreach ($this->cardTokens($card) as $token) {
            $normalized = $this->interpreter->normalize($token);

            if ($normalized !== '' && mb_strlen($normalized) >= 3 && str_contains($haystack, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function monthLabel(\DateTimeInterface $date): string
    {
        return [
            1 => 'Janeiro',
            2 => 'Fevereiro',
            3 => 'Março',
            4 => 'Abril',
            5 => 'Maio',
            6 => 'Junho',
            7 => 'Julho',
            8 => 'Agosto',
            9 => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro',
        ][(int) $date->format('n')];
    }

    private function formatMoney(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        $unsigned = ltrim($amount, '+-');
        [$whole, $decimal] = array_pad(explode('.', $unsigned, 2), 2, '00');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');

        return ($negative ? '-R$ ' : 'R$ ')
            .number_format((int) $whole, 0, '', '.')
            .','.$decimal;
    }
}
