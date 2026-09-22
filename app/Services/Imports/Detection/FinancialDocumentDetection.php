<?php

namespace App\Services\Imports\Detection;

final readonly class FinancialDocumentDetection
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $documentType,
        public ?string $institution,
        public float $confidence,
        public string $format,
        public ?string $parserKey = null,
        public ?string $identifierType = null,
        public ?string $identifierValue = null,
        public ?string $referenceMonth = null,
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'document_type' => $this->documentType,
            'institution' => $this->institution,
            'confidence' => $this->confidence,
            'format' => $this->format,
            'parser_key' => $this->parserKey,
            'identifier_type' => $this->identifierType,
            'identifier_value' => $this->identifierValue,
            'reference_month' => $this->referenceMonth,
            'metadata' => $this->metadata,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            documentType: (string) ($data['document_type'] ?? 'unknown'),
            institution: is_string($data['institution'] ?? null) ? $data['institution'] : null,
            confidence: (float) ($data['confidence'] ?? 0),
            format: (string) ($data['format'] ?? 'unknown'),
            parserKey: is_string($data['parser_key'] ?? null) ? $data['parser_key'] : null,
            identifierType: is_string($data['identifier_type'] ?? null) ? $data['identifier_type'] : null,
            identifierValue: is_string($data['identifier_value'] ?? null) ? $data['identifier_value'] : null,
            referenceMonth: is_string($data['reference_month'] ?? null) ? $data['reference_month'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    public function importKind(): ?string
    {
        return match ($this->documentType) {
            'bank_statement', 'payment_account_statement' => 'statement',
            'credit_card_statement' => 'invoice',
            default => null,
        };
    }
}
