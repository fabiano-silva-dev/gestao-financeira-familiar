<?php

namespace App\Services\Reconciliation;

use App\Enums\AccountMovementType;
use App\Models\FinancialImport;

final class ImportedMovementInterpreter
{
    public function moneyToCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $unsigned = ltrim($amount, '+-');
        [$whole, $decimal] = array_pad(explode('.', $unsigned, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    public function unsignedAmount(string $amount): string
    {
        return ltrim($amount, '+-');
    }

    public function isOutflow(string $amount): bool
    {
        return $this->moneyToCents($amount) < 0;
    }

    /**
     * @param  array<int, string>  $cardTokens
     */
    public function isInvoicePayment(string $description, array $cardTokens = []): bool
    {
        $normalized = $this->normalize($description);

        if (
            $normalized === 'pagamento recebido'
            || str_contains($normalized, 'pagamento recebido')
            || str_contains($normalized, 'pagamento da fatura')
            || str_contains($normalized, 'pagamento de fatura')
            || str_contains($normalized, 'payment received')
            || str_contains($normalized, 'fatura nubank')
            || str_contains($normalized, 'pagamento fatura')
            || str_contains($normalized, 'pagamento cartao de credito')
            || str_contains($normalized, 'pagamento nubank')
        ) {
            return true;
        }

        if (! str_contains($normalized, 'pagamento')) {
            return false;
        }

        foreach ($cardTokens as $token) {
            $card = $this->normalize($token);

            if ($card !== '' && mb_strlen($card) >= 4 && str_contains($normalized, $card)) {
                return true;
            }
        }

        return false;
    }

    public function isLikelyRefund(string $description): bool
    {
        $normalized = $this->normalize($description);

        return str_contains($normalized, 'reembolso')
            || str_contains($normalized, 'estorno')
            || str_contains($normalized, 'devolucao')
            || str_contains($normalized, 'refund');
    }

    public function isLikelyTransfer(string $description, ?string $movementType = null): bool
    {
        if (in_array($movementType, [
            AccountMovementType::TransferOut->value,
            AccountMovementType::TransferIn->value,
        ], true)) {
            return true;
        }

        $normalized = $this->normalize($description);
        $tokens = ' '.$normalized.' ';

        return str_contains($normalized, 'transferencia')
            || str_contains($tokens, ' transf ')
            || str_contains($normalized, 'pix transf')
            || str_contains($normalized, 'dinheiro reservado')
            || str_contains($normalized, 'dinheiro retirado');
    }

    public function descriptionSimilarity(string $left, string $right): float
    {
        $left = $this->normalize($left);
        $right = $this->normalize($right);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right || str_contains($left, $right) || str_contains($right, $left)) {
            return 1.0;
        }

        similar_text($left, $right, $characterPercentage);
        $leftTokens = array_values(array_unique(array_filter(
            explode(' ', $left),
            static fn (string $token): bool => mb_strlen($token) >= 3,
        )));
        $rightTokens = array_values(array_unique(array_filter(
            explode(' ', $right),
            static fn (string $token): bool => mb_strlen($token) >= 3,
        )));
        $union = array_unique([...$leftTokens, ...$rightTokens]);
        $tokenSimilarity = $union === []
            ? 0.0
            : count(array_intersect($leftTokens, $rightTokens)) / count($union);

        return max($characterPercentage / 100, $tokenSimilarity);
    }

    public function sourceFormatLabel(?FinancialImport $import, string $kind): string
    {
        $format = strtolower((string) data_get($import?->metadata, 'source_format', ''));
        $channel = strtolower((string) data_get($import?->metadata, 'source_channel', ''));

        return match (true) {
            $channel === 'gmail' || $format === 'gmail' => 'Gmail',
            $channel === 'whatsapp' || $format === 'whatsapp' => 'WhatsApp',
            in_array($format, ['ofx', 'qfx'], true) => 'OFX',
            $format === 'pdf' => 'PDF',
            $format === 'csv' => 'CSV',
            in_array($format, ['xls', 'xlsx'], true) => 'Planilha',
            $kind === 'invoice' => 'Fatura',
            default => 'Extrato',
        };
    }

    public function normalize(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($transliterated) ? $transliterated : $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
