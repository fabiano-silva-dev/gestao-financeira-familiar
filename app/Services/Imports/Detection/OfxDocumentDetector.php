<?php

namespace App\Services\Imports\Detection;

use App\Services\Imports\OfxParseException;
use App\Services\Imports\OfxParser;

final class OfxDocumentDetector implements FinancialDocumentDetector
{
    public function __construct(
        private readonly OfxParser $parser,
        private readonly InstitutionMatcher $institutions,
    ) {}

    public function supports(string $extension, ?string $mimeType): bool
    {
        return in_array($extension, ['ofx', 'qfx'], true);
    }

    public function detect(
        string $contents,
        string $filename,
        string $extension,
        ?string $mimeType,
    ): ?FinancialDocumentDetection {
        try {
            $statement = $this->parser->parse($contents);
        } catch (OfxParseException) {
            return null;
        }

        return new FinancialDocumentDetection(
            documentType: 'bank_statement',
            institution: $this->institutions->fromBankId($statement->bankId)
                ?? $this->institutions->detect($filename),
            confidence: 1.0,
            format: $extension,
            parserKey: 'ofx',
            identifierType: $statement->accountId !== null ? 'account_id' : null,
            identifierValue: $statement->accountId,
            metadata: array_filter([
                'bank_id' => $statement->bankId,
                'period_start' => $statement->startOn,
                'period_end' => $statement->endOn,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}
