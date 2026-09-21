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
}
