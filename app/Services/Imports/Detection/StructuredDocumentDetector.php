<?php

namespace App\Services\Imports\Detection;

use App\Services\Imports\BankStatementCsvParser;
use App\Services\Imports\BankStatementParseException;
use App\Services\Imports\CardStatementParseException;
use App\Services\Imports\CardStatementParser;

final class StructuredDocumentDetector implements FinancialDocumentDetector
{
    public function __construct(
        private readonly BankStatementCsvParser $bankParser,
        private readonly CardStatementParser $cardParser,
        private readonly InstitutionMatcher $institutions,
    ) {}

    public function supports(string $extension, ?string $mimeType): bool
    {
        return in_array($extension, ['csv', 'xls', 'xlsx'], true);
    }

    public function detect(
        string $contents,
        string $filename,
        string $extension,
        ?string $mimeType,
    ): ?FinancialDocumentDetection {
        $institution = $this->institutions->detect($filename.' '.$this->textPrefix($contents));
        $referenceMonth = $this->referenceMonthFromFilename($filename);
        $filenameHints = $this->filenameHints($filename);
        $institution ??= $filenameHints['institution'];

        if ($extension !== 'csv') {
            try {
                $this->cardParser->parse($contents, $extension, 'auto');
            } catch (CardStatementParseException) {
                return new FinancialDocumentDetection(
                    documentType: 'unknown',
                    institution: $institution,
                    confidence: $institution !== null ? 0.55 : 0.25,
                    format: $extension,
                );
            }

            return new FinancialDocumentDetection(
                documentType: 'credit_card_statement',
                institution: $institution,
                confidence: 0.92,
                format: $extension,
                parserKey: 'card_table',
                referenceMonth: $referenceMonth,
            );
        }

        $normalized = $this->normalize($contents);
        $firstLine = $this->firstMeaningfulLine($normalized);

        if ($firstLine === 'data valor identificador descricao') {
            return new FinancialDocumentDetection(
                documentType: 'bank_statement',
                institution: $institution,
                confidence: 0.99,
                format: 'csv',
                parserKey: 'bank_csv',
                identifierType: $filenameHints['identifier_type'],
                identifierValue: $filenameHints['identifier_value'],
            );
        }

        if (
            str_contains($normalized, 'release date')
            && str_contains($normalized, 'transaction type')
            && str_contains($normalized, 'transaction net amount')
        ) {
            return new FinancialDocumentDetection(
                documentType: 'payment_account_statement',
                institution: $institution ?? 'mercado_pago',
                confidence: 0.99,
                format: 'csv',
                parserKey: 'bank_csv',
            );
        }

        if ($firstLine === 'date title amount') {
            return new FinancialDocumentDetection(
                documentType: 'credit_card_statement',
                institution: $institution,
                confidence: 0.97,
                format: 'csv',
                parserKey: 'card_table',
                referenceMonth: $referenceMonth,
            );
        }

        $cardMarkers = $this->containsAny($normalized, [
            'parcela',
            'estabelecimento',
            'merchant',
            'categoria nubank',
            'installment',
            'numero do cartao',
            'final do cartao',
            'card number',
        ]);
        $bankMarkers = $this->containsAny($normalized, [
            'saldo',
            'debito',
            'credito',
            'fitid',
            'transaction type',
            'partial balance',
            'reference id',
            'pagamento de fatura',
            'transferencia recebida',
            'pix recebido',
            'pix enviado',
            'pagamento de boleto',
        ]);
        $bankValid = $this->canParseBank($contents);
        $cardValid = $this->canParseCard($contents);

        if ($bankValid && ! $cardValid) {
            return new FinancialDocumentDetection(
                documentType: 'bank_statement',
                institution: $institution,
                confidence: 0.94,
                format: 'csv',
                parserKey: 'bank_csv',
            );
        }

        if ($cardValid && ! $bankValid) {
            return new FinancialDocumentDetection(
                documentType: 'credit_card_statement',
                institution: $institution,
                confidence: 0.94,
                format: 'csv',
                parserKey: 'card_table',
                referenceMonth: $referenceMonth,
            );
        }

        if ($bankValid && $cardValid && $bankMarkers && ! $cardMarkers) {
            return new FinancialDocumentDetection(
                documentType: 'bank_statement',
                institution: $institution,
                confidence: 0.90,
                format: 'csv',
                parserKey: 'bank_csv',
                identifierType: $filenameHints['identifier_type'],
                identifierValue: $filenameHints['identifier_value'],
            );
        }

        if ($bankValid && $cardValid && $cardMarkers && ! $bankMarkers) {
            return new FinancialDocumentDetection(
                documentType: 'credit_card_statement',
                institution: $institution,
                confidence: 0.90,
                format: 'csv',
                parserKey: 'card_table',
                referenceMonth: $referenceMonth,
            );
        }

        if (
            $bankValid
            && $cardValid
            && $filenameHints['document_type'] === 'bank_statement'
            && ! $cardMarkers
        ) {
            return new FinancialDocumentDetection(
                documentType: 'bank_statement',
                institution: $institution,
                confidence: 0.86,
                format: 'csv',
                parserKey: 'bank_csv',
                identifierType: $filenameHints['identifier_type'],
                identifierValue: $filenameHints['identifier_value'],
            );
        }

        if (
            $bankValid
            && $cardValid
            && $filenameHints['document_type'] === 'credit_card_statement'
            && ! $bankMarkers
        ) {
            return new FinancialDocumentDetection(
                documentType: 'credit_card_statement',
                institution: $institution,
                confidence: 0.86,
                format: 'csv',
                parserKey: 'card_table',
                referenceMonth: $referenceMonth,
            );
        }

        return new FinancialDocumentDetection(
            documentType: 'unknown',
            institution: $institution,
            confidence: $institution !== null ? 0.55 : 0.3,
            format: 'csv',
            metadata: [
                'bank_parser_compatible' => $bankValid,
                'card_parser_compatible' => $cardValid,
            ],
        );
    }

    private function canParseBank(string $contents): bool
    {
        try {
            $this->bankParser->parse($contents);

            return true;
        } catch (BankStatementParseException) {
            return false;
        }
    }

    private function canParseCard(string $contents): bool
    {
        try {
            $this->cardParser->parse($contents, 'csv', 'auto');

            return true;
        } catch (CardStatementParseException) {
            return false;
        }
    }

    /**
     * @return array{
     *     institution: ?string,
     *     document_type: ?string,
     *     identifier_type: ?string,
     *     identifier_value: ?string
     * }
     */
    private function filenameHints(string $filename): array
    {
        $basename = basename(str_replace('\\', '/', $filename));
        $normalized = $this->normalizeFilename($basename);
        $institution = null;
        $documentType = null;
        $identifierType = null;
        $identifierValue = null;

        if (preg_match('/^NU[_\\-\\s]+(\\d{6,20})(?:[_\\-\\s]|$)/i', $basename, $match) === 1) {
            $institution = 'nubank';
            $documentType = 'bank_statement';
            $identifierType = 'account_number';
            $identifierValue = $match[1];
        } elseif (preg_match('/^NUBANK(?:[_\\-\\s]|$)/i', $basename) === 1) {
            $institution = 'nubank';

            if (preg_match('/^NUBANK[_\\-\\s]+20\\d{2}[-_]\\d{2}[-_]\\d{2}/i', $basename) === 1) {
                $documentType = 'credit_card_statement';
            }
        }

        if (
            str_contains($normalized, 'account statement')
            || str_contains($normalized, 'bank statement')
            || str_contains($normalized, 'extrato')
        ) {
            $documentType = 'bank_statement';
        } elseif (str_contains($normalized, 'fatura')) {
            $documentType = 'credit_card_statement';
        }

        return [
            'institution' => $institution,
            'document_type' => $documentType,
            'identifier_type' => $identifierType,
            'identifier_value' => $identifierValue,
        ];
    }

    private function normalizeFilename(string $filename): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
        $filename = is_string($converted) ? $converted : $filename;
        $filename = mb_strtolower($filename);

        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $filename) ?? $filename);
    }

    private function referenceMonthFromFilename(string $filename): ?string
    {
        if (preg_match('/(20\d{2})[-_](0[1-9]|1[0-2])(?:[-_]\d{2})?/', $filename, $match) === 1) {
            return $match[1].'-'.$match[2];
        }

        return null;
    }

    private function firstMeaningfulLine(string $value): string
    {
        foreach (preg_split('/\R/u', $value) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                return trim(preg_replace('/[^a-z0-9]+/u', ' ', $line) ?? $line);
            }
        }

        return '';
    }

    private function textPrefix(string $contents): string
    {
        if (str_contains($contents, "\0")) {
            return '';
        }

        return mb_substr($contents, 0, 12000);
    }

    private function normalize(string $value): string
    {
        $value = $this->textPrefix($value);
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($converted) ? $converted : $value;
        $value = mb_strtolower($value);

        return preg_replace('/[^a-z0-9\n]+/u', ' ', $value) ?? $value;
    }

    /** @param list<string> $needles */
    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
