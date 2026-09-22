<?php

namespace App\Services\Imports\Detection;

interface FinancialDocumentDetector
{
    public function supports(string $extension, ?string $mimeType): bool;

    public function detect(
        string $contents,
        string $filename,
        string $extension,
        ?string $mimeType,
    ): ?FinancialDocumentDetection;
}
