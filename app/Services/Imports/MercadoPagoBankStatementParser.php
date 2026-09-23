<?php

namespace App\Services\Imports;

use App\Services\Imports\Data\OfxStatement;
use App\Services\Imports\Data\OfxTransaction;
use DateTimeImmutable;

final class MercadoPagoBankStatementParser
{
    private const MAX_ROWS = 10000;

    private const TRANSACTION_PATTERN = '/^\s*(\d{2}-\d{2}-\d{4})\s+(.*?)\s+(\d{6,20})\s+R\$\s*(-?[\d.]+,\d{2})\s+R\$\s*-?[\d.]+,\d{2}\s*$/u';

    public function __construct(
        private readonly PdfTextExtractor $textExtractor,
    ) {}

    public function parse(string $contents): OfxStatement
    {
        $text = str_replace("\f", "\n", $this->textExtractor->extract($contents));
        $lines = preg_split('/\R/u', $text) ?: [];

        return $this->parseLines($lines);
    }

    /**
     * @param  array<int, string>  $lines
     */
    public function parseLines(array $lines): OfxStatement
    {
        $text = implode("\n", $lines);
        $normalized = mb_strtoupper($this->ascii($text));

        if (
            ! str_contains($normalized, 'MERCADO PAGO')
            || ! str_contains($normalized, 'EXTRATO DE CONTA')
            || ! str_contains($normalized, 'DETALHE DOS MOVIMENTOS')
        ) {
            throw new BankStatementParseException(
                'O PDF não corresponde ao extrato de conta do Mercado Pago.',
            );
        }

        $transactions = [];

        foreach ($lines as $index => $line) {
            if (preg_match(self::TRANSACTION_PATTERN, $line, $match) !== 1) {
                continue;
            }

            $amount = $this->parseAmount($match[4]);

            if ($amount === '0.00') {
                continue;
            }

            $transactions[] = new OfxTransaction(
                occurredOn: $this->parseDate($match[1]),
                amount: $amount,
                transactionType: str_starts_with($amount, '-') ? 'DEBIT' : 'CREDIT',
                externalId: $match[3],
                description: mb_substr(
                    $this->description($lines, $index, $match[2]),
                    0,
                    255,
                ),
                memo: null,
                checkNumber: null,
                referenceNumber: $match[3],
            );

            if (count($transactions) > self::MAX_ROWS) {
                throw new BankStatementParseException(
                    'O extrato ultrapassa o limite de 10.000 movimentos por arquivo.',
                );
            }
        }

        [$startOn, $endOn] = $this->period($text);

        if ($transactions !== []) {
            $dates = array_map(
                static fn (OfxTransaction $transaction): string => $transaction->occurredOn,
                $transactions,
            );
            $startOn ??= min($dates);
            $endOn ??= max($dates);
        }

        return new OfxStatement(
            bankId: '323',
            accountId: $this->accountId($text),
            currency: 'BRL',
            startOn: $startOn,
            endOn: $endOn,
            transactions: $transactions,
        );
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function description(array $lines, int $rowIndex, string $inline): string
    {
        $parts = [];
        $before = [];

        for ($index = $rowIndex - 1; $index >= 0; $index--) {
            $candidate = trim($lines[$index]);

            if (
                $candidate === ''
                || $this->isTransactionRow($lines[$index])
                || $this->isStructuralLine($candidate)
            ) {
                break;
            }

            array_unshift($before, $candidate);
        }

        $parts = [...$before];
        $inline = trim($inline);

        if ($inline !== '') {
            $parts[] = $inline;
        }

        for ($index = $rowIndex + 1, $count = count($lines); $index < $count; $index++) {
            $candidate = trim($lines[$index]);

            if (
                $candidate === ''
                || $this->isTransactionRow($lines[$index])
                || $this->isStructuralLine($candidate)
            ) {
                break;
            }

            $parts[] = $candidate;
        }

        $description = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');

        return $description !== '' ? $description : 'Movimento Mercado Pago';
    }

    private function isTransactionRow(string $line): bool
    {
        return preg_match(self::TRANSACTION_PATTERN, $line) === 1;
    }

    private function isStructuralLine(string $line): bool
    {
        $normalized = mb_strtoupper($this->ascii(trim($line)));

        if ($normalized === '' || preg_match('/^\d+\/\d+$/', $normalized) === 1) {
            return true;
        }

        if (
            str_starts_with($normalized, 'EXTRATO DE CONTA')
            || str_starts_with($normalized, 'CPF/CNPJ:')
            || str_starts_with($normalized, 'PERIODO:')
            || str_starts_with($normalized, 'SALDO INICIAL:')
            || str_starts_with($normalized, 'ENTRADAS:')
            || str_starts_with($normalized, 'SAIDAS:')
            || str_starts_with($normalized, 'SALDO FINAL:')
            || str_starts_with($normalized, 'DATA DE GERACAO:')
            || str_contains($normalized, 'DETALHE DOS MOVIMENTOS')
        ) {
            return true;
        }

        return str_contains($normalized, 'DATA')
            && str_contains($normalized, 'DESCRICAO')
            && str_contains($normalized, 'ID DA OPERACAO')
            && str_contains($normalized, 'VALOR')
            && str_contains($normalized, 'SALDO');
    }

    private function parseDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!d-m-Y', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            ! $date instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new BankStatementParseException(
                "A data {$value} do extrato do Mercado Pago não foi reconhecida.",
            );
        }

        return $date->format('Y-m-d');
    }

    private function parseAmount(string $value): string
    {
        $negative = str_starts_with(trim($value), '-');
        $numeric = ltrim(trim($value), '+-');
        $numeric = str_replace('.', '', $numeric);
        $numeric = str_replace(',', '.', $numeric);

        if (preg_match('/^(?<whole>\d+)\.(?<decimal>\d{2})$/', $numeric, $match) !== 1) {
            throw new BankStatementParseException(
                "O valor {$value} do extrato do Mercado Pago não foi reconhecido.",
            );
        }

        $whole = ltrim($match['whole'], '0');
        $whole = $whole === '' ? '0' : $whole;
        $amount = $whole.'.'.$match['decimal'];

        return $negative && $amount !== '0.00' ? '-'.$amount : $amount;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function period(string $text): array
    {
        $ascii = $this->ascii($text);

        if (preg_match(
            '/PERIODO:\s*DE\s*(\d{2}-\d{2}-\d{4})\s+(?:A|AL|ATE)\s+(\d{2}-\d{2}-\d{4})/i',
            $ascii,
            $match,
        ) !== 1) {
            return [null, null];
        }

        return [
            $this->parseDate($match[1]),
            $this->parseDate($match[2]),
        ];
    }

    private function accountId(string $text): ?string
    {
        if (preg_match('/\bCONTA:\s*([\d.\-]+)/i', $this->ascii($text), $match) !== 1) {
            return null;
        }

        $account = preg_replace('/\D/', '', $match[1]) ?? '';

        return $account !== '' ? $account : null;
    }

    private function ascii(string $value): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($converted) ? $converted : $value;
    }
}
