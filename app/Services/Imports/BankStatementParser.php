<?php

namespace App\Services\Imports;

use App\Services\Imports\Data\OfxStatement;

final class BankStatementParser
{
    public function __construct(
        private readonly OfxParser $ofxParser,
        private readonly BankStatementCsvParser $csvParser,
        private readonly BanrisulBankStatementParser $banrisulParser,
    ) {}

    public function parse(string $contents, string $extension): OfxStatement
    {
        return match (strtolower($extension)) {
            'ofx', 'qfx' => $this->ofxParser->parse($contents),
            'csv' => $this->csvParser->parse($contents),
            'pdf' => $this->banrisulParser->parse($contents),
            default => throw new BankStatementParseException(
                'O arquivo deve estar nos formatos OFX, CSV ou PDF.',
            ),
        };
    }
}
