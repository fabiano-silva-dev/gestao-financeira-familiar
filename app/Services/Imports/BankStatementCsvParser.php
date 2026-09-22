<?php

namespace App\Services\Imports;

use App\Services\Imports\Data\OfxStatement;
use App\Services\Imports\Data\OfxTransaction;
use DateTimeImmutable;

final class BankStatementCsvParser
{
    private const MAX_ROWS = 10000;

    /** @var array<string, array<int, string>> */
    private const HEADER_ALIASES = [
        'date' => [
            'data',
            'date',
            'data movimento',
            'data do movimento',
            'data lancamento',
            'data do lancamento',
            'data transacao',
            'data da transacao',
            'posted date',
            'transaction date',
            'release date',
        ],
        'description' => [
            'descricao',
            'description',
            'historico',
            'lancamento',
            'memo',
            'detalhes',
            'titulo',
            'title',
            'transaction type',
        ],
        'amount' => [
            'valor',
            'amount',
            'value',
            'valor r',
            'valor rs',
            'transaction net amount',
            'net amount',
        ],
        'debit' => [
            'debito',
            'debit',
            'saida',
            'retirada',
        ],
        'credit' => [
            'credito',
            'credit',
            'entrada',
        ],
        'external_id' => [
            'id',
            'identificador',
            'documento',
            'fitid',
            'id transacao',
            'transaction id',
            'reference id',
        ],
    ];

    public function parse(string $contents): OfxStatement
    {
        $rows = $this->readCsv($contents);

        if ($rows === []) {
            throw new BankStatementParseException('O CSV não possui linhas para importar.');
        }

        [$headerIndex, $columns] = $this->locateHeader($rows);
        $transactions = [];

        foreach (array_slice($rows, $headerIndex + 1, null, true) as $index => $cells) {
            $cells = array_map(fn (string $cell): string => $this->clean($cell), $cells);

            if ($this->isBlank($cells)) {
                continue;
            }

            $dateValue = $this->cell($cells, $columns['date']);
            $description = $this->cell($cells, $columns['description']);
            $amountValue = isset($columns['amount'])
                ? $this->cell($cells, $columns['amount'])
                : '';

            if ($dateValue === '' || $description === '') {
                continue;
            }

            if ($this->isBalanceDescription($description)) {
                continue;
            }

            if ($amountValue === '' && isset($columns['debit'], $columns['credit'])) {
                $debit = $this->cell($cells, $columns['debit']);
                $credit = $this->cell($cells, $columns['credit']);
                $amount = $this->amountFromDebitCredit($debit, $credit, $index + 1);
            } else {
                $amount = $this->parseAmount($amountValue, $index + 1);
            }

            if ($amount === '0.00') {
                continue;
            }

            $externalId = isset($columns['external_id'])
                ? $this->cell($cells, $columns['external_id'])
                : '';

            $transactions[] = new OfxTransaction(
                occurredOn: $this->parseDate($dateValue, $index + 1),
                amount: $amount,
                transactionType: str_starts_with($amount, '-') ? 'DEBIT' : 'CREDIT',
                externalId: $externalId === '' ? null : mb_substr($externalId, 0, 64),
                description: mb_substr($description, 0, 255),
                memo: null,
                checkNumber: null,
                referenceNumber: $externalId === '' ? null : mb_substr($externalId, 0, 64),
            );

            if (count($transactions) > self::MAX_ROWS) {
                throw new BankStatementParseException(
                    'O extrato ultrapassa o limite de 10.000 movimentos por arquivo.',
                );
            }
        }

        if ($transactions === []) {
            throw new BankStatementParseException('Nenhum movimento válido foi encontrado no CSV.');
        }

        $dates = array_map(
            static fn (OfxTransaction $transaction): string => $transaction->occurredOn,
            $transactions,
        );

        return new OfxStatement(
            bankId: null,
            accountId: null,
            currency: 'BRL',
            startOn: min($dates),
            endOn: max($dates),
            transactions: $transactions,
        );
    }

    /** @return array<int, array<int, string>> */
    private function readCsv(string $contents): array
    {
        $contents = $this->toUtf8($contents);

        if (str_contains($contents, "\0")) {
            throw new BankStatementParseException('O CSV contém dados binários inválidos.');
        }

        $delimiter = $this->detectDelimiter($contents);
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false || fwrite($stream, $contents) === false) {
            throw new BankStatementParseException('Não foi possível preparar o CSV para leitura.');
        }

        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(
                static fn (mixed $cell): string => is_string($cell) ? $cell : '',
                $row,
            );

            if (count($rows) > self::MAX_ROWS + 100) {
                fclose($stream);

                throw new BankStatementParseException(
                    'O extrato ultrapassa o limite de 10.000 movimentos por arquivo.',
                );
            }
        }

        fclose($stream);

        return $rows;
    }

    private function detectDelimiter(string $contents): string
    {
        $lines = preg_split('/\R/u', $contents, 20) ?: [];
        $bestDelimiter = ',';
        $bestScore = -1;

        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $score = 0;
            $previousColumns = null;

            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }

                $columns = count(str_getcsv($line, $delimiter, '"', ''));

                if ($columns > 1) {
                    $score += ($columns - 1) * 3;
                    $score += $previousColumns === null || $previousColumns === $columns ? 1 : -1;
                    $previousColumns = $columns;
                }
            }

            if ($score > $bestScore) {
                $bestDelimiter = $delimiter;
                $bestScore = $score;
            }
        }

        if ($bestScore <= 0) {
            throw new BankStatementParseException('Não foi possível identificar as colunas do CSV.');
        }

        return $bestDelimiter;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array{0: int, 1: array<string, int>}
     */
    private function locateHeader(array $rows): array
    {
        foreach ($rows as $index => $cells) {
            $mapped = $this->mapColumns($cells);

            if (isset($mapped['date'], $mapped['description'])
                && (isset($mapped['amount']) || (isset($mapped['debit'], $mapped['credit'])))
            ) {
                return [$index, $mapped];
            }
        }

        throw new BankStatementParseException(
            'O CSV precisa das colunas de data, histórico e valor.',
        );
    }

    /**
     * @param  array<int, string>  $cells
     * @return array<string, int>
     */
    private function mapColumns(array $cells): array
    {
        $mapped = [];

        foreach ($cells as $index => $header) {
            $normalized = $this->normalizeHeader($header);

            foreach (self::HEADER_ALIASES as $column => $aliases) {
                if (! isset($mapped[$column]) && in_array($normalized, $aliases, true)) {
                    $mapped[$column] = $index;
                }
            }
        }

        return $mapped;
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function cell(array $cells, int $index): string
    {
        return $this->clean($cells[$index] ?? '');
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($this->clean($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseDate(string $value, int $row): string
    {
        $candidate = preg_replace('/[T ]\d{2}:\d{2}.*$/', '', trim($value)) ?? trim($value);

        foreach (['!d/m/Y', '!d/m/y', '!Y-m-d', '!d-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $candidate);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        throw new BankStatementParseException("A data da linha {$row} não foi reconhecida.");
    }

    private function parseAmount(string $value, int $row): string
    {
        $value = trim(str_replace(["\u{00A0}", '−'], [' ', '-'], $value));

        if ($value === '') {
            throw new BankStatementParseException("O valor da linha {$row} está vazio.");
        }

        $negative = str_contains($value, '-')
            || (str_starts_with($value, '(') && str_ends_with($value, ')'));
        $numeric = preg_replace('/[^0-9.,]/u', '', $value) ?? '';

        if ($numeric === '' || preg_match('/\d/', $numeric) !== 1) {
            throw new BankStatementParseException("O valor da linha {$row} não foi reconhecido.");
        }

        $lastComma = strrpos($numeric, ',');
        $lastDot = strrpos($numeric, '.');
        $decimalSeparator = null;

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false || $lastDot !== false) {
            $separator = $lastComma !== false ? ',' : '.';
            $position = $lastComma !== false ? $lastComma : $lastDot;
            $decimalDigits = strlen($numeric) - $position - 1;

            if ($decimalDigits > 0 && $decimalDigits <= 2) {
                $decimalSeparator = $separator;
            }
        }

        if ($decimalSeparator !== null) {
            $position = strrpos($numeric, $decimalSeparator);
            $whole = preg_replace('/\D/', '', substr($numeric, 0, (int) $position)) ?? '';
            $decimal = preg_replace('/\D/', '', substr($numeric, ((int) $position) + 1)) ?? '';
        } else {
            $whole = preg_replace('/\D/', '', $numeric) ?? '';
            $decimal = '';
        }

        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');
        $money = $whole.'.'.$decimal;

        return $negative && $money !== '0.00' ? '-'.$money : $money;
    }

    private function amountFromDebitCredit(string $debit, string $credit, int $row): string
    {
        $hasDebit = trim($debit) !== '';
        $hasCredit = trim($credit) !== '';

        if ($hasDebit === $hasCredit) {
            throw new BankStatementParseException(
                "A linha {$row} precisa ter débito ou crédito, não os dois.",
            );
        }

        $amount = $this->parseAmount($hasDebit ? $debit : $credit, $row);

        if ($hasDebit && ! str_starts_with($amount, '-')) {
            return $amount === '0.00' ? $amount : '-'.$amount;
        }

        return ltrim($amount, '-');
    }

    private function isBalanceDescription(string $description): bool
    {
        return (bool) preg_match(
            '/^SALDO(\s+(ANTERIOR|ANT\b|NA\s+DATA|DO\s+DIA|FINAL|INICIAL|TOTAL|EM\s+CONTA|DISPON)|$)/i',
            trim($description),
        );
    }

    private function normalizeHeader(string $value): string
    {
        $value = $this->clean($value);
        $value = str_replace(['$', 'R$'], ' ', $value);
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return mb_strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $value)));
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $value)));
    }

    private function toUtf8(string $contents): string
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $converted = @mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1,Windows-1252');

            return is_string($converted) ? $converted : $contents;
        }

        return $contents;
    }
}
