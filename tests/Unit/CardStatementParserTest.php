<?php

namespace Tests\Unit;

use App\Services\Imports\CardStatementParseException;
use App\Services\Imports\CardStatementParser;
use Tests\TestCase;
use ZipArchive;

class CardStatementParserTest extends TestCase
{
    public function test_parses_brazilian_csv_and_extracts_installments(): void
    {
        $csv = <<<'CSV'
            Data da compra;Estabelecimento;Valor (R$);Parcela;Identificador
            10/09/2026;Vôlei Lidiane;89,90;2/10;linha-001
            12/09/2026;Loja Central PARC 03/06;1.234,56;;
            CSV;

        $statement = app(CardStatementParser::class)->parse($csv, 'csv', 'positive');

        $this->assertSame('csv', $statement->sourceFormat);
        $this->assertCount(2, $statement->rows);
        $this->assertSame('2026-09-10', $statement->rows[0]->purchasedOn);
        $this->assertSame('89.90', $statement->rows[0]->amount);
        $this->assertSame(2, $statement->rows[0]->installmentNumber);
        $this->assertSame(10, $statement->rows[0]->totalInstallments);
        $this->assertSame('linha-001', $statement->rows[0]->externalId);
        $this->assertSame('1234.56', $statement->rows[1]->amount);
        $this->assertSame(3, $statement->rows[1]->installmentNumber);
        $this->assertSame(6, $statement->rows[1]->totalInstallments);
    }

    public function test_normalizes_windows_encoding_and_negative_purchase_convention(): void
    {
        $utf8 = "Data,Descrição,Valor\n20/09/2026,Farmácia,-35.90\n21/09/2026,Estorno,10.00\n";
        $windows = mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');

        $statement = app(CardStatementParser::class)->parse($windows, 'csv', 'negative');

        $this->assertSame('Farmácia', $statement->rows[0]->description);
        $this->assertSame('35.90', $statement->rows[0]->amount);
        $this->assertSame('-10.00', $statement->rows[1]->amount);
    }

    public function test_parses_spreadsheet_xml_xls(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
                xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
                <Worksheet ss:Name="Fatura">
                    <Table>
                        <Row>
                            <Cell><Data ss:Type="String">Data</Data></Cell>
                            <Cell><Data ss:Type="String">Estabelecimento</Data></Cell>
                            <Cell><Data ss:Type="String">Valor</Data></Cell>
                        </Row>
                        <Row>
                            <Cell><Data ss:Type="String">15/09/2026</Data></Cell>
                            <Cell><Data ss:Type="String">Handebol Luiza 4/8</Data></Cell>
                            <Cell><Data ss:Type="Number">120.50</Data></Cell>
                        </Row>
                    </Table>
                </Worksheet>
            </Workbook>
            XML;

        $statement = app(CardStatementParser::class)->parse($xml, 'xls', 'positive');

        $this->assertSame('xls-xml', $statement->sourceFormat);
        $this->assertCount(1, $statement->rows);
        $this->assertSame('120.50', $statement->rows[0]->amount);
        $this->assertSame(4, $statement->rows[0]->installmentNumber);
        $this->assertSame(8, $statement->rows[0]->totalInstallments);
    }

    public function test_parses_first_worksheet_from_xlsx(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('A extensão ZIP não está disponível.');
        }

        $statement = app(CardStatementParser::class)->parse(
            $this->xlsxFile(),
            'xlsx',
            'positive',
        );

        $this->assertSame('xlsx', $statement->sourceFormat);
        $this->assertCount(1, $statement->rows);
        $this->assertSame('2026-09-18', $statement->rows[0]->purchasedOn);
        $this->assertSame('Mercado da Família', $statement->rows[0]->description);
        $this->assertSame('245.67', $statement->rows[0]->amount);
    }

    public function test_parses_nubank_csv_skipping_payments_and_keeping_purchases_positive(): void
    {
        $csv = <<<'CSV'
            date,title,amount
            2026-05-22,99app *99app,"8,08"
            2026-05-15,Localiza - Parcela 1/6,"93,63"
            2026-05-10,Farmacia Sao Joao,"33,89"
            2026-05-08,Pagamento recebido,"- 2.307,13"
            2026-05-07,Pagamento recebido,"- 500,00"
            CSV;

        $statement = app(CardStatementParser::class)->parse($csv, 'csv', 'negative');

        $this->assertCount(3, $statement->rows);
        $this->assertSame(2, $statement->ignoredRows);
        $this->assertSame('8.08', $statement->rows[0]->amount);
        $this->assertSame('93.63', $statement->rows[1]->amount);
        $this->assertSame(1, $statement->rows[1]->installmentNumber);
        $this->assertSame(6, $statement->rows[1]->totalInstallments);
        $this->assertSame('33.89', $statement->rows[2]->amount);
    }

    public function test_maps_category_column_and_auto_detects_negative_purchases(): void
    {
        $csv = <<<'CSV'
            date,category,title,amount
            2026-05-10,transporte,Uber UberX,-18.90
            2026-05-11,supermercado,Mercado da Família,-45.00
            CSV;

        $statement = app(CardStatementParser::class)->parse($csv, 'csv', 'auto');

        $this->assertCount(2, $statement->rows);
        $this->assertSame('18.90', $statement->rows[0]->amount);
        $this->assertSame('transporte', $statement->rows[0]->sourceCategory);
        $this->assertSame('45.00', $statement->rows[1]->amount);
        $this->assertSame('supermercado', $statement->rows[1]->sourceCategory);
    }

    public function test_parses_mercado_pago_pdf_skipping_payments_and_extracting_installments(): void
    {
        $statement = app(CardStatementParser::class)->parse(
            $this->mercadoPagoPdf(),
            'pdf',
            'auto',
            'mercado_pago_credit_card',
        );

        $this->assertSame('pdf-mercado-pago', $statement->sourceFormat);
        $this->assertSame(2, $statement->ignoredRows);
        $this->assertCount(3, $statement->rows);
        $this->assertSame('2026-07-09', $statement->rows[0]->purchasedOn);
        $this->assertSame('Smhigienizacoes Parcela 2 de 3', $statement->rows[0]->description);
        $this->assertSame('93.33', $statement->rows[0]->amount);
        $this->assertSame(2, $statement->rows[0]->installmentNumber);
        $this->assertSame(3, $statement->rows[0]->totalInstallments);
        $this->assertSame('Visa · 3736', $statement->rows[0]->rawData['Cartão']);
        $this->assertSame('2026-08-05', $statement->rows[1]->purchasedOn);
        $this->assertSame(1, $statement->rows[1]->installmentNumber);
        $this->assertSame(6, $statement->rows[1]->totalInstallments);
        $this->assertSame('8.60', $statement->rows[2]->amount);
        $this->assertSame('Visa · 3759', $statement->rows[2]->rawData['Cartão']);
    }

    public function test_rejects_unsupported_card_pdf_layout(): void
    {
        $this->expectException(CardStatementParseException::class);
        $this->expectExceptionMessage('não é válido para fatura de cartão');

        app(CardStatementParser::class)->parse(
            $this->mercadoPagoPdf(),
            'pdf',
            'auto',
            'banrisul_current_account',
        );
    }

    public function test_rejects_files_without_required_headers(): void
    {
        $this->expectException(CardStatementParseException::class);
        $this->expectExceptionMessage('colunas de data, descrição e valor');

        app(CardStatementParser::class)->parse(
            "Campo A;Campo B\nUm;Dois\n",
            'csv',
            'positive',
        );
    }

    public function test_rejects_external_xml_declarations(): void
    {
        $this->expectException(CardStatementParseException::class);
        $this->expectExceptionMessage('declarações externas');

        app(CardStatementParser::class)->parse(
            '<!DOCTYPE Workbook [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><Workbook>&xxe;</Workbook>',
            'xls',
            'positive',
        );
    }

    private function xlsxFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'statement-test-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('xl/workbook.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
                xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
                <sheets><sheet name="Fatura" sheetId="1" r:id="rId1"/></sheets>
            </workbook>
            XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
            </Relationships>
            XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
                <sheetData>
                    <row r="1">
                        <c r="A1" t="inlineStr"><is><t>Data</t></is></c>
                        <c r="B1" t="inlineStr"><is><t>Descrição</t></is></c>
                        <c r="C1" t="inlineStr"><is><t>Valor</t></is></c>
                    </row>
                    <row r="2">
                        <c r="A2" t="inlineStr"><is><t>18/09/2026</t></is></c>
                        <c r="B2" t="inlineStr"><is><t>Mercado da Família</t></is></c>
                        <c r="C2"><v>245.67</v></c>
                    </row>
                </sheetData>
            </worksheet>
            XML);
        $zip->close();
        $contents = file_get_contents($path);
        @unlink($path);
        $this->assertIsString($contents);

        return $contents;
    }

    private function mercadoPagoPdf(): string
    {
        return $this->pdfWithLines([
            'Pague sua fatura pelo app Mercado Pago',
            'Vencimento: 08/09/2026',
            'Detalhes de consumo',
            'Movimentações na fatura',
            '04/08 Pagamento da fatura de agosto/2026 R$ 1.500,00',
            '07/08 Pagamento da fatura de agosto/2026 R$ 2.108,26',
            'Cartão Visa [************3736]',
            '09/07 Smhigienizacoes Parcela 2 de 3 R$ 93,33',
            '05/08 VEST COMPANHIA Parcela 1 de 6 R$ 91,80',
            'Cartão Visa [************3759]',
            '04/08 DL*99 RIDE R$ 8,60',
            'Parcele a fatura do seu Cartão de Crédito Mercado Pago',
        ]);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function pdfWithLines(array $lines): string
    {
        $stream = "BT /F1 9 Tf\n";
        $y = 750;

        foreach ($lines as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $stream .= sprintf("1 0 0 1 24 %d Tm (%s) Tj\n", $y, $escaped);
            $y -= 14;
        }

        $stream .= 'ET';
        $length = strlen($stream);

        return <<<PDF
        %PDF-1.4
        1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
        2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj
        3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj
        4 0 obj<</Length {$length}>>stream
        {$stream}
        endstream
        endobj
        5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Courier>>endobj
        trailer<</Root 1 0 R>>
        %%EOF
        PDF;
    }
}
