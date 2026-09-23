<?php

namespace App\Services\Imports;

use App\Services\Imports\Data\OfxStatement;

final class BankStatementParser
{
    public function __construct(
        private readonly OfxParser $ofxParser,
        private readonly BankStatementCsvParser $csvParser,
        private readonly BanrisulBankStatementParser $banrisulParser,
        private readonly MercadoPagoBankStatementParser $mercadoPagoParser,
    ) {}

    public function parse(
        string $contents,
        string $extension,
        ?string $pdfLayout = null,
    ): OfxStatement {
        return match (strtolower($extension)) {
            'ofx', 'qfx' => $this->ofxParser->parse($contents),
            'csv' => $this->csvParser->parse($contents),
            'pdf' => $this->parsePdf($contents, $pdfLayout),
            default => throw new BankStatementParseException(
                'O arquivo deve estar nos formatos OFX, CSV ou PDF.',
            ),
        };
    }

    private function parsePdf(string $contents, ?string $layout): OfxStatement
    {
        return match ($layout) {
            'mercado_pago_account_statement' => $this->mercadoPagoParser->parse($contents),
            'banrisul_current_account', null => $this->banrisulParser->parse($contents),
            default => throw new BankStatementParseException(
                'O layout informado para o PDF do extrato não é suportado.',
            ),
        };
    }
}
