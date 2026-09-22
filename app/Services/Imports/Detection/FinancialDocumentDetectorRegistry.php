<?php

namespace App\Services\Imports\Detection;

final class FinancialDocumentDetectorRegistry
{
    /** @var list<FinancialDocumentDetector> */
    private array $detectors;

    public function __construct(
        OfxDocumentDetector $ofx,
        PdfDocumentDetector $pdf,
        StructuredDocumentDetector $structured,
        private readonly InstitutionMatcher $institutions,
    ) {
        $this->detectors = [$ofx, $pdf, $structured];
    }

    public function detect(
        string $contents,
        string $filename,
        string $extension,
        ?string $mimeType,
    ): FinancialDocumentDetection {
        foreach ($this->detectors as $detector) {
            if (! $detector->supports($extension, $mimeType)) {
                continue;
            }

            $detected = $detector->detect($contents, $filename, $extension, $mimeType);

            if ($detected instanceof FinancialDocumentDetection) {
                return $detected;
            }
        }

        return new FinancialDocumentDetection(
            documentType: 'unknown',
            institution: $this->institutions->detect($filename),
            confidence: 0.1,
            format: $extension !== '' ? $extension : 'unknown',
        );
    }
}
