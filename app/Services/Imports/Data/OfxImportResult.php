<?php

namespace App\Services\Imports\Data;

use App\Models\FinancialImport;

final readonly class OfxImportResult
{
    public function __construct(
        public FinancialImport $import,
        public bool $alreadyImported,
    ) {}
}
