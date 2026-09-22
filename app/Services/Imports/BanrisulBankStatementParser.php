<?php

namespace App\Services\Imports;

use App\Services\Imports\Data\OfxStatement;
use App\Services\Imports\Data\OfxTransaction;
use DateTimeImmutable;

final class BanrisulBankStatementParser
{
    /** @var array<string, int> */
    private const MONTHS = [
        'JAN' => 1,
        'JANEIRO' => 1,
        'FEV' => 2,
        'FEVEREIRO' => 2,
        'MAR' => 3,
        'MARCO' => 3,
        'MARÇO' => 3,
        'ABR' => 4,
        'ABRIL' => 4,
        'MAI' => 5,
        'MAIO' => 5,
        'JUN' => 6,
        'JUNHO' => 6,
        'JUL' => 7,
        'JULHO' => 7,
        'AGO' => 8,
        'AGOSTO' => 8,
        'SET' => 9,
        'SETEMBRO' => 9,
        'OUT' => 10,
        'OUTUBRO' => 10,
        'NOV' => 11,
        'NOVEMBRO' => 11,
        'DEZ' => 12,
        'DEZEMBRO' => 12,
    ];

    public function __construct(
        private readonly PdfTextExtractor $textExtractor,
    ) {}

    public function parse(string $contents): OfxStatement
    {
        $lines = preg_split('/\R/u', $this->textExtractor->extract($contents)) ?: [];

        return $this->parseLines($lines);
    }

    /**
     * @param  array<int, string>  $lines
     */
    public function parseLines(array $lines): OfxStatement
    {
        $text = implode("\n", $lines);

        if (
            ! str_contains(mb_strtoupper($text), 'BANRISUL')
            && ! str_contains(mb_strtoupper($text), 'MOVIMENTOS DA CONTA CORRENTE')
        ) {
            throw new BankStatementParseException(
                'Por enquanto, o PDF suportado é o extrato de conta corrente do Banrisul.',
            );
        }

        $transactions = $this->transactions($lines);

        if ($transactions === []) {
            throw new BankStatementParseException(
                'Nenhum lançamento válido foi encontrado no PDF do Banrisul.',
            );
        }

        $dates = array_map(
            static fn (OfxTransaction $transaction): string => $transaction->occurredOn,
            $transactions,
        );
        $account = $this->accountData($lines);

        return new OfxStatement(
            bankId: '041',
            accountId: $account['account_id'],
            currency: 'BRL',
            startOn: min($dates),
            endOn: max($dates),
            transactions: $transactions,
        );
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, OfxTransaction>
     */
    private function transactions(array $lines): array
    {
        [$month, $year] = $this->periodContext($lines);
        $day = null;
        $insideMovements = false;
        $pendingName = null;
        $transactions = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || preg_match('/^--\s+\d+\s+of\s+\d+\s+--$/i', $line) === 1) {
                continue;
            }

            if (str_contains(mb_strtoupper($line), 'MOVIMENTOS DA CONTA CORRENTE')) {
                $insideMovements = true;

                continue;
            }

            if (preg_match('/\+\+\s+MOVIMENTOS\s+([A-Z]{3})\/(\d{4})/i', $line, $monthMatch) === 1) {
                $insideMovements = true;
                $year = (int) $monthMatch[2];
                $month = self::MONTHS[strtoupper($monthMatch[1])] ?? $month;
                $day = null;

                continue;
            }

            if (! $insideMovements) {
                continue;
            }

            if (str_starts_with($line, '-') || str_starts_with($line, '+-')) {
                continue;
            }

            if (preg_match('/^SALDO\s+(ANT|NA\s+DATA)\b/i', $line) === 1) {
                continue;
            }

            if (preg_match('/^NOME:\s*(.+)$/i', $line, $nameMatch) === 1) {
                $name = trim($nameMatch[1]);

                if ($transactions !== []) {
                    $last = array_key_last($transactions);
                    $transactions[$last] = $this->withName($transactions[$last], $name);
                } else {
                    $pendingName = $name;
                }

                continue;
            }

            if (preg_match(
                '/^(?:(\d{2})\s+)?(.+?)\s+([A-Z0-9]{4,8})\s+(\d{1,3}(?:\.\d{3})*,\d{2})(-)?\s*$/iu',
                $line,
                $match,
            ) !== 1) {
                continue;
            }

            if (($match[1] ?? '') !== '') {
                $day = $match[1];
            }

            if ($day === null) {
                continue;
            }

            $occurredOn = $this->composeDate($day, $month, $year);

            if ($occurredOn === null) {
                continue;
            }

            $amount = $this->parseAmount($match[4], ($match[5] ?? '') !== '');

            if ($amount === '0.00' || $this->isBalanceDescription($match[2])) {
                continue;
            }

            $description = trim($match[2]);
            $name = $pendingName;
            $pendingName = null;
            $memo = $this->memo($description, $name);
            $transactions[] = new OfxTransaction(
                occurredOn: $occurredOn,
                amount: $amount,
                transactionType: str_starts_with($amount, '-') ? 'DEBIT' : 'CREDIT',
                externalId: null,
                description: mb_substr($memo, 0, 255),
                memo: $name,
                checkNumber: null,
                referenceNumber: $match[3],
            );
        }

        return $transactions;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{account_id: string|null}
     */
    private function accountData(array $lines): array
    {
        $accountNumber = '';

        foreach (array_slice($lines, 0, 40) as $rawLine) {
            $line = trim($rawLine);

            if (preg_match('/CONTA\.{2,}:\s*([\d.\-]+)/i', $line, $match) === 1) {
                $accountNumber = $match[1];
            }
        }

        $accountId = preg_replace('/\D/', '', $accountNumber) ?? '';

        return [
            'account_id' => $accountId === '' ? null : $accountId,
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{0: int, 1: int}
     */
    private function periodContext(array $lines): array
    {
        $now = new DateTimeImmutable;
        $month = (int) $now->format('n');
        $year = (int) $now->format('Y');
        $header = implode("\n", array_slice($lines, 0, 80));

        if (preg_match('/B\s*A\s*N\s*R\s*I\s*S\s*U\s*L\s+(\d{2}\/\d{2}\/\d{4})/i', $header, $match) === 1) {
            $date = DateTimeImmutable::createFromFormat('!d/m/Y', $match[1]);

            if ($date instanceof DateTimeImmutable) {
                $month = (int) $date->format('n');
                $year = (int) $date->format('Y');
            }
        }

        if (preg_match('/SALDO\s+ANT\s+EM\s+(\d{2}\/\d{2}\/\d{4})/i', $header, $match) === 1) {
            $date = DateTimeImmutable::createFromFormat('!d/m/Y', $match[1]);

            if ($date instanceof DateTimeImmutable) {
                $year = (int) $date->format('Y');
            }
        }

        if (preg_match('/PERIODO:\s*([A-ZÇ]+)\s*\/\s*(\d{4})/iu', $header, $match) === 1) {
            $year = (int) $match[2];
            $monthName = mb_strtoupper($this->asciiMonth($match[1]));
            $month = self::MONTHS[$monthName] ?? $month;
        }

        return [$month, $year];
    }

    private function asciiMonth(string $value): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($converted) && $converted !== '' ? $converted : $value;
    }

    private function composeDate(string $day, int $month, int $year): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', $year, $month, (int) $day));
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function parseAmount(string $value, bool $debit): string
    {
        $normalized = str_replace('.', '', $value);
        $normalized = str_replace(',', '.', $normalized);

        if (preg_match('/^(?<whole>\d+)(?:\.(?<decimal>\d{1,2}))?$/', $normalized, $matches) !== 1) {
            return '0.00';
        }

        $whole = ltrim($matches['whole'], '0');
        $whole = $whole === '' ? '0' : $whole;
        $decimal = str_pad($matches['decimal'] ?? '', 2, '0');
        $amount = "{$whole}.{$decimal}";

        if ($amount === '0.00') {
            return $amount;
        }

        return $debit ? "-{$amount}" : $amount;
    }

    private function isBalanceDescription(string $description): bool
    {
        $upper = mb_strtoupper(trim($description));

        return (bool) preg_match(
            '/^SALDO(\s+(ANTERIOR|ANT\b|NA\s+DATA|DO\s+DIA|FINAL|INICIAL|TOTAL|EM\s+CONTA|DISPON)|$)/',
            $upper,
        );
    }

    private function memo(string $description, ?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '' || str_contains(mb_strtoupper($description), mb_strtoupper($name))) {
            return $description;
        }

        return "{$description} - {$name}";
    }

    private function withName(OfxTransaction $transaction, string $name): OfxTransaction
    {
        return new OfxTransaction(
            occurredOn: $transaction->occurredOn,
            amount: $transaction->amount,
            transactionType: $transaction->transactionType,
            externalId: $transaction->externalId,
            description: mb_substr($this->memo($transaction->description, $name), 0, 255),
            memo: $name,
            checkNumber: $transaction->checkNumber,
            referenceNumber: $transaction->referenceNumber,
        );
    }
}
