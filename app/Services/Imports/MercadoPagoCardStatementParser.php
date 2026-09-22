<?php

namespace App\Services\Imports;

use DateTimeImmutable;

final class MercadoPagoCardStatementParser
{
    public function __construct(
        private readonly PdfTextExtractor $textExtractor,
    ) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function parse(string $contents): array
    {
        try {
            $text = $this->textExtractor->extract($contents);
        } catch (BankStatementParseException $exception) {
            throw new CardStatementParseException(
                'Não foi possível ler o texto do PDF da fatura.',
                previous: $exception,
            );
        }

        $lines = preg_split('/\R/u', $text) ?: [];

        return $this->parseLines($lines);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array<int, string>>
     */
    public function parseLines(array $lines): array
    {
        $text = implode("\n", $lines);

        if (! $this->isMercadoPagoInvoice($text)) {
            throw new CardStatementParseException(
                'O layout selecionado é a fatura em PDF do Mercado Pago, mas o arquivo não corresponde a essa fatura.',
            );
        }

        $dueDate = $this->dueDate($text);
        $rows = $this->rows($lines, $dueDate);

        if ($rows === []) {
            throw new CardStatementParseException(
                'Nenhum lançamento válido foi encontrado no PDF do Mercado Pago.',
            );
        }

        return [
            ['Data', 'Descrição', 'Valor', 'Cartão'],
            ...$rows,
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array<int, string>>
     */
    private function rows(array $lines, DateTimeImmutable $dueDate): array
    {
        $insideDetails = false;
        $cardLabel = '';
        $rows = [];

        foreach ($lines as $rawLine) {
            $line = $this->normalizeLine($rawLine);

            if ($line === '' || preg_match('/^--\s+\d+\s+of\s+\d+\s+--$/i', $line) === 1) {
                continue;
            }

            if ($this->isTrailerSection($line)) {
                break;
            }

            if (preg_match('/(\S+)\s+\[\*+(\d{4})\]$/u', $line, $cardMatch) === 1) {
                $insideDetails = true;
                $cardLabel = $cardMatch[1].' · '.$cardMatch[2];

                continue;
            }

            $normalized = mb_strtolower($line);

            if (
                str_contains($normalized, 'detalhes de consumo')
                || str_contains($normalized, 'movimentações na fatura')
            ) {
                $insideDetails = true;

                continue;
            }

            if (! $insideDetails) {
                continue;
            }

            if (preg_match(
                '/^(\d{2}\/\d{2})\s+(.+)\s+R\$\s*([-\d.]+,\d{2})$/u',
                $line,
                $match,
            ) !== 1) {
                continue;
            }

            $rows[] = [
                $this->expandDate($match[1], $dueDate),
                $match[2],
                $match[3],
                $cardLabel,
            ];
        }

        return $rows;
    }

    private function isMercadoPagoInvoice(string $text): bool
    {
        $normalized = mb_strtoupper($this->ascii($text));

        if (! str_contains($normalized, 'MERCADO PAGO')) {
            return false;
        }

        return str_contains($normalized, 'DETALHES DE CONSUMO')
            || str_contains($normalized, 'MOVIMENTACOES NA FATURA')
            || str_contains($normalized, 'CARTAO');
    }

    private function isTrailerSection(string $line): bool
    {
        $normalized = mb_strtolower($line);

        foreach ([
            'parcele a fatura',
            'seu cartão de crédito',
            'compras internacionais',
            'lançamentos futuros',
            'opções de pagamento',
        ] as $marker) {
            if (str_starts_with($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function dueDate(string $text): DateTimeImmutable
    {
        if (preg_match('/Vencimento:\s*(\d{2}\/\d{2}\/\d{4})/i', $text, $match) === 1) {
            return $this->fullDate($match[1], 'vencimento');
        }

        $collapsed = $this->normalizeLine($text);

        if (preg_match('/Vence em\s+(\d{2}\/\d{2}\/\d{4})/i', $collapsed, $match) === 1) {
            return $this->fullDate($match[1], 'vencimento');
        }

        if (preg_match('/Emitida em:\s*(\d{2}\/\d{2}\/\d{4})/i', $collapsed, $match) === 1) {
            return $this->fullDate($match[1], 'emissão');
        }

        throw new CardStatementParseException(
            'Não foi possível identificar a data de vencimento da fatura do Mercado Pago.',
        );
    }

    private function fullDate(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new CardStatementParseException(
                "A data de {$field} '{$value}' não foi reconhecida.",
            );
        }

        return $date;
    }

    private function expandDate(string $dayMonth, DateTimeImmutable $dueDate): string
    {
        [$day, $month] = array_map('intval', explode('/', $dayMonth));
        $year = (int) $dueDate->format('Y');
        $date = $this->composeDate($year, $month, $day);

        if ($date === null) {
            throw new CardStatementParseException(
                "A data '{$dayMonth}' não foi reconhecida na fatura do Mercado Pago.",
            );
        }

        if ($date > $dueDate->modify('+20 days')) {
            $date = $this->composeDate($year - 1, $month, $day);
        }

        if ($date === null) {
            throw new CardStatementParseException(
                "A data '{$dayMonth}' não foi reconhecida na fatura do Mercado Pago.",
            );
        }

        return $date->format('Y-m-d');
    }

    private function composeDate(int $year, int $month, int $day): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-n-j',
            sprintf('%d-%d-%d', $year, $month, $day),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            return null;
        }

        return $date;
    }

    private function normalizeLine(string $line): string
    {
        $line = str_replace("\u{00A0}", ' ', $line);

        return trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
    }

    private function ascii(string $value): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($converted) && $converted !== '' ? $converted : $value;
    }
}
