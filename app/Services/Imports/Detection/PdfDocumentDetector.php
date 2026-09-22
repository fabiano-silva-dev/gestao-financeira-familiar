<?php

namespace App\Services\Imports\Detection;

use App\Services\Imports\BankStatementParseException;
use App\Services\Imports\PdfTextExtractor;
use DateTimeImmutable;

final class PdfDocumentDetector implements FinancialDocumentDetector
{
    public function __construct(
        private readonly PdfTextExtractor $textExtractor,
        private readonly InstitutionMatcher $institutions,
    ) {}

    public function supports(string $extension, ?string $mimeType): bool
    {
        return $extension === 'pdf' || $mimeType === 'application/pdf';
    }

    public function detect(
        string $contents,
        string $filename,
        string $extension,
        ?string $mimeType,
    ): ?FinancialDocumentDetection {
        try {
            $text = $this->textExtractor->extract($contents);
        } catch (BankStatementParseException) {
            return new FinancialDocumentDetection(
                documentType: 'unknown',
                institution: $this->institutions->detect($filename),
                confidence: 0.2,
                format: 'pdf',
            );
        }

        $normalized = mb_strtoupper($this->ascii($text));
        $institution = $this->institutions->detect($text.' '.$filename);

        if ($this->isBanrisulCurrentAccount($normalized)) {
            $account = null;

            if (preg_match('/CONTA\.{2,}:\s*([\d.\-]+)/i', $text, $match) === 1) {
                $account = preg_replace('/\D/', '', $match[1]) ?: null;
            }

            $holder = null;

            if (preg_match('/NOME\.{2,}:\s*(.+)/u', $text, $match) === 1) {
                $holder = trim($match[1]);
                $holder = $holder !== '' ? $holder : null;
            }

            $agency = null;

            if (preg_match('/AGENCIA:\s*(\d+)/i', $text, $match) === 1) {
                $agency = $match[1];
            }

            return new FinancialDocumentDetection(
                documentType: 'bank_statement',
                institution: 'banrisul',
                confidence: 0.99,
                format: 'pdf',
                parserKey: 'banrisul_current_account',
                identifierType: $account !== null ? 'account_number' : null,
                identifierValue: $account,
                referenceMonth: $this->banrisulReferenceMonth($normalized),
                holderName: $holder,
                metadata: array_filter([
                    'agency' => $agency,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        if (
            str_contains($normalized, 'MERCADO PAGO')
            && (
                str_contains($normalized, 'DETALHES DE CONSUMO')
                || str_contains($normalized, 'MOVIMENTACOES NA FATURA')
            )
        ) {
            $lastFour = null;

            if (preg_match('/\[\*+(\d{4})\]/u', $text, $match) === 1) {
                $lastFour = $match[1];
            }

            return new FinancialDocumentDetection(
                documentType: 'credit_card_statement',
                institution: 'mercado_pago',
                confidence: 0.99,
                format: 'pdf',
                parserKey: 'mercado_pago_credit_card',
                identifierType: $lastFour !== null ? 'card_last_four' : null,
                identifierValue: $lastFour,
                referenceMonth: $this->referenceMonth($text),
                holderName: $this->mercadoPagoHolderName($text),
            );
        }

        if (str_contains($normalized, 'COMPROVANTE')) {
            return new FinancialDocumentDetection(
                documentType: 'proof',
                institution: $institution,
                confidence: $institution !== null ? 0.88 : 0.72,
                format: 'pdf',
            );
        }

        if (str_contains($normalized, 'FATURA')) {
            return new FinancialDocumentDetection(
                documentType: 'credit_card_statement',
                institution: $institution,
                confidence: 0.68,
                format: 'pdf',
            );
        }

        if (
            str_contains($normalized, 'EXTRATO')
            || str_contains($normalized, 'MOVIMENTOS DA CONTA')
        ) {
            return new FinancialDocumentDetection(
                documentType: 'bank_statement',
                institution: $institution,
                confidence: 0.68,
                format: 'pdf',
            );
        }

        return new FinancialDocumentDetection(
            documentType: 'unknown',
            institution: $institution,
            confidence: $institution !== null ? 0.55 : 0.2,
            format: 'pdf',
        );
    }

    private function isBanrisulCurrentAccount(string $normalized): bool
    {
        if (! str_contains($normalized, 'MOVIMENTOS DA CONTA CORRENTE')) {
            return false;
        }

        return preg_match('/B\s*A\s*N\s*R\s*I\s*S\s*U\s*L/', $normalized) === 1;
    }

    private function banrisulReferenceMonth(string $normalized): ?string
    {
        if (preg_match('/MOVIMENTOS\s+([A-Z]{3})\/(\d{4})/', $normalized, $match) !== 1) {
            return null;
        }

        $months = [
            'JAN' => 1,
            'FEV' => 2,
            'MAR' => 3,
            'ABR' => 4,
            'MAI' => 5,
            'JUN' => 6,
            'JUL' => 7,
            'AGO' => 8,
            'SET' => 9,
            'OUT' => 10,
            'NOV' => 11,
            'DEZ' => 12,
        ];
        $month = $months[$match[1]] ?? null;

        if ($month === null) {
            return null;
        }

        return sprintf('%04d-%02d', (int) $match[2], $month);
    }

    private function mercadoPagoHolderName(string $text): ?string
    {
        foreach ([
            '/\A\s*([^\r\n]{2,120})\R+\s*Emitida em:/u',
            '/(?:\A|\R)\s*([^\r\n]{2,120})\R+\s*Vencimento:/u',
        ] as $pattern) {
            if (preg_match($pattern, $text, $match) !== 1) {
                continue;
            }

            $name = trim(preg_replace('/\s+/u', ' ', $match[1]) ?? $match[1]);

            if ($this->looksLikePersonName($name)) {
                return $name;
            }
        }

        return null;
    }

    private function looksLikePersonName(string $value): bool
    {
        if (mb_strlen($value) < 2 || mb_strlen($value) > 120) {
            return false;
        }

        $normalized = mb_strtoupper($this->ascii($value));

        foreach ([
            'MERCADO PAGO',
            'CARTAO',
            'FATURA',
            'DETALHES',
            'VENCIMENTO',
            'EMITIDA EM',
        ] as $forbidden) {
            if (str_contains($normalized, $forbidden)) {
                return false;
            }
        }

        return preg_match('/[A-Za-zÀ-ÿ]/u', $value) === 1;
    }

    private function referenceMonth(string $text): ?string
    {
        foreach ([
            '/Vencimento:\s*(\d{2}\/\d{2}\/\d{4})/i',
            '/Vence em\s+(\d{2}\/\d{2}\/\d{4})/i',
        ] as $pattern) {
            if (preg_match($pattern, $text, $match) !== 1) {
                continue;
            }

            $date = DateTimeImmutable::createFromFormat('!d/m/Y', $match[1]);

            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m');
            }
        }

        return null;
    }

    private function ascii(string $value): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($converted) ? $converted : $value;
    }
}
