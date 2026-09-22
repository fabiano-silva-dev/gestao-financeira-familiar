<?php

namespace App\Services\Imports;

use App\Services\Imports\Data\CardStatement;
use App\Services\Imports\Data\CardStatementRow;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

final class CardStatementParser
{
    private const MAX_ROWS = 10000;

    private const MAX_UNCOMPRESSED_BYTES = 80 * 1024 * 1024;

    /** @var array<string, array<int, string>> */
    private const HEADER_ALIASES = [
        'date' => [
            'data',
            'date',
            'data compra',
            'data da compra',
            'data transacao',
            'data da transacao',
            'data lancamento',
            'data do lancamento',
            'purchase date',
            'transaction date',
            'posted date',
        ],
        'description' => [
            'descricao',
            'description',
            'estabelecimento',
            'merchant',
            'lancamento',
            'historico',
            'title',
            'titulo',
            'nome',
            'detalhes',
        ],
        'amount' => [
            'valor',
            'amount',
            'value',
            'valor compra',
            'valor da compra',
            'valor r',
            'valor rs',
            'total',
        ],
        'installment' => [
            'parcela',
            'numero parcela',
            'numero da parcela',
            'n parcela',
            'installment',
            'installment number',
        ],
        'total_installments' => [
            'total parcelas',
            'total de parcelas',
            'quantidade parcelas',
            'quantidade de parcelas',
            'installments',
            'total installments',
        ],
        'external_id' => [
            'id',
            'identificador',
            'codigo',
            'id transacao',
            'id da transacao',
            'transaction id',
            'external id',
        ],
        'category' => [
            'categoria',
            'category',
            'categoria nubank',
        ],
    ];

    /** @var array<int, string> */
    private const PAYMENT_TITLES = [
        'pagamento recebido',
        'pagamento da fatura',
        'pagamento de fatura',
        'pagamento fatura',
        'payment received',
        'bill payment',
        'credit card payment',
    ];

    public function __construct(
        private readonly MercadoPagoCardStatementParser $mercadoPagoParser,
    ) {}

    public function parse(
        string $contents,
        string $extension,
        string $amountSign,
        ?string $pdfLayout = null,
    ): CardStatement {
        if (! in_array($amountSign, ['positive', 'negative', 'auto'], true)) {
            throw new CardStatementParseException(
                'A convenção de sinal escolhida para os valores é inválida.',
            );
        }

        $extension = strtolower($extension);
        [$table, $sourceFormat] = match ($extension) {
            'csv' => [$this->readCsv($contents), 'csv'],
            'xlsx' => [$this->readXlsx($contents), 'xlsx'],
            'xls' => $this->readLegacyXls($contents),
            'pdf' => $this->readPdf($contents, $pdfLayout),
            default => throw new CardStatementParseException(
                'O arquivo deve estar nos formatos CSV, XLS, XLSX ou PDF.',
            ),
        };

        return $this->normalizeTable($table, $sourceFormat, $amountSign);
    }

    /**
     * @return array{array<int, array<int, string>>, string}
     */
    private function readPdf(string $contents, ?string $pdfLayout): array
    {
        if ($pdfLayout !== null && $pdfLayout !== 'mercado_pago_credit_card') {
            throw new CardStatementParseException(
                'O layout de PDF selecionado não é válido para fatura de cartão.',
            );
        }

        return [$this->mercadoPagoParser->parse($contents), 'pdf-mercado-pago'];
    }

    /**
     * @param  array<int, array<int, string>>  $table
     */
    private function normalizeTable(
        array $table,
        string $sourceFormat,
        string $amountSign,
    ): CardStatement {
        if ($table === []) {
            throw new CardStatementParseException('O arquivo não possui linhas para importar.');
        }

        [$headerIndex, $columns] = $this->locateHeader($table);
        $headers = array_map(
            fn (string $header): string => $this->cleanCell($header),
            $table[$headerIndex],
        );
        $rows = [];
        $ignoredRows = 0;

        foreach (array_slice($table, $headerIndex + 1, null, true) as $index => $cells) {
            $cells = array_map(fn (string $cell): string => $this->cleanCell($cell), $cells);

            if ($this->isBlankRow($cells)) {
                $ignoredRows++;

                continue;
            }

            $dateValue = $this->cell($cells, $columns['date']);
            $description = $this->cell($cells, $columns['description']);
            $amountValue = $this->cell($cells, $columns['amount']);
            $displayRow = $index + 1;

            if ($dateValue === '') {
                $ignoredRows++;

                continue;
            }

            if ($description === '') {
                throw new CardStatementParseException(
                    "A linha {$displayRow} não possui estabelecimento ou descrição.",
                );
            }

            if ($amountValue === '') {
                throw new CardStatementParseException(
                    "A linha {$displayRow} não possui valor.",
                );
            }

            try {
                $purchasedOn = $this->parseDate($dateValue);
                $amount = $this->parseMoney($amountValue);
            } catch (CardStatementParseException $exception) {
                throw new CardStatementParseException(
                    "Linha {$displayRow}: {$exception->getMessage()}",
                    previous: $exception,
                );
            }

            if ($this->isInvoicePayment($description)) {
                $ignoredRows++;

                continue;
            }

            if ($amountSign === 'negative') {
                $amount = $this->invertMoney($amount);
            }

            [$installmentNumber, $totalInstallments] = $this->parseInstallment(
                $this->optionalCell($cells, $columns['installment'] ?? null),
                $this->optionalCell($cells, $columns['total_installments'] ?? null),
                $description,
                $displayRow,
            );
            $externalId = $this->optionalCell($cells, $columns['external_id'] ?? null);
            $sourceCategory = $this->optionalCell($cells, $columns['category'] ?? null);

            $rows[] = new CardStatementRow(
                purchasedOn: $purchasedOn,
                description: mb_substr($description, 0, 255),
                amount: $amount,
                installmentNumber: $installmentNumber,
                totalInstallments: $totalInstallments,
                externalId: $externalId !== '' ? mb_substr($externalId, 0, 255) : null,
                rawData: $this->rawData($headers, $cells),
                sourceCategory: $sourceCategory !== '' ? mb_substr($sourceCategory, 0, 120) : null,
            );

            if (count($rows) > self::MAX_ROWS) {
                throw new CardStatementParseException(
                    'A fatura ultrapassa o limite de 10.000 lançamentos por arquivo.',
                );
            }
        }

        if ($rows === []) {
            throw new CardStatementParseException(
                'Nenhum lançamento válido foi encontrado após o cabeçalho.',
            );
        }

        return new CardStatement(
            $this->normalizePurchaseDirection($rows),
            $headers,
            $sourceFormat,
            $ignoredRows,
        );
    }

    /**
     * @param  array<int, array<int, string>>  $table
     * @return array{int, array<string, int>}
     */
    private function locateHeader(array $table): array
    {
        foreach (array_slice($table, 0, 30, true) as $index => $row) {
            $normalized = array_map(
                fn (string $value): string => $this->normalizeHeader($value),
                $row,
            );
            $columns = [];

            foreach (self::HEADER_ALIASES as $field => $aliases) {
                foreach ($normalized as $column => $value) {
                    if (in_array($value, $aliases, true)) {
                        $columns[$field] = $column;

                        break;
                    }
                }
            }

            if (isset($columns['date'], $columns['description'], $columns['amount'])) {
                return [$index, $columns];
            }
        }

        throw new CardStatementParseException(
            'Não foi possível identificar as colunas de data, descrição e valor. Use esses nomes no cabeçalho e tente novamente.',
        );
    }

    /** @return array<int, array<int, string>> */
    private function readCsv(string $contents): array
    {
        $contents = $this->toUtf8($contents);

        if (str_contains($contents, "\0")) {
            throw new CardStatementParseException('O CSV contém dados binários inválidos.');
        }

        $delimiter = $this->detectDelimiter($contents);
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false || fwrite($stream, $contents) === false) {
            throw new CardStatementParseException('Não foi possível preparar o CSV para leitura.');
        }

        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(
                static fn (mixed $cell): string => is_string($cell) ? $cell : '',
                $row,
            );

            if (count($rows) > self::MAX_ROWS + 100) {
                fclose($stream);

                throw new CardStatementParseException(
                    'A fatura ultrapassa o limite de 10.000 lançamentos por arquivo.',
                );
            }
        }

        fclose($stream);

        return $rows;
    }

    private function detectDelimiter(string $contents): string
    {
        $lines = preg_split('/\R/u', $contents, 20) ?: [];
        $bestDelimiter = ',';
        $bestScore = -1;

        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $score = 0;
            $previousColumns = null;

            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }

                $columns = count(str_getcsv($line, $delimiter, '"', ''));

                if ($columns > 1) {
                    $score += ($columns - 1) * 3;
                    $score += $previousColumns === null || $previousColumns === $columns ? 1 : -1;
                    $previousColumns = $columns;
                }
            }

            if ($score > $bestScore) {
                $bestDelimiter = $delimiter;
                $bestScore = $score;
            }
        }

        if ($bestScore <= 0) {
            throw new CardStatementParseException(
                'Não foi possível identificar as colunas do CSV.',
            );
        }

        return $bestDelimiter;
    }

    /** @return array<int, array<int, string>> */
    private function readXlsx(string $contents): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new CardStatementParseException(
                'O servidor não possui suporte a XLSX. Importe a fatura em CSV.',
            );
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'card-statement-');

        if ($temporaryPath === false || file_put_contents($temporaryPath, $contents) === false) {
            throw new CardStatementParseException('Não foi possível preparar o XLSX para leitura.');
        }

        $zip = new ZipArchive;
        $opened = false;

        try {
            if ($zip->open($temporaryPath) !== true) {
                throw new CardStatementParseException('O arquivo XLSX está corrompido ou protegido.');
            }

            $opened = true;
            $this->assertSafeArchive($zip);
            $sharedStrings = $this->xlsxSharedStrings($zip);
            $worksheetPath = $this->firstWorksheetPath($zip);
            $worksheet = $zip->getFromName($worksheetPath);

            if (! is_string($worksheet)) {
                throw new CardStatementParseException(
                    'A primeira planilha do XLSX não pôde ser lida.',
                );
            }

            return $this->xlsxRows($worksheet, $sharedStrings);
        } finally {
            if ($opened) {
                $zip->close();
            }

            @unlink($temporaryPath);
        }
    }

    private function assertSafeArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > 1000) {
            throw new CardStatementParseException('O XLSX possui arquivos internos em excesso.');
        }

        $uncompressedBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $statistics = $zip->statIndex($index);

            if (is_array($statistics)) {
                $uncompressedBytes += $statistics['size'];
            }

            if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw new CardStatementParseException(
                    'O conteúdo descompactado do XLSX ultrapassa o limite de segurança.',
                );
            }
        }
    }

    /** @return array<int, string> */
    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if (! is_string($xml)) {
            return [];
        }

        $document = $this->parseXml($xml, 'a lista de textos do XLSX');
        $xpath = new DOMXPath($document);
        $strings = [];

        $items = $xpath->query('//*[local-name()="si"]');

        if ($items === false) {
            throw new CardStatementParseException('Não foi possível ler a lista de textos do XLSX.');
        }

        foreach ($items as $item) {
            if ($item instanceof DOMNode) {
                $strings[] = $this->descendantText($item, 't');
            }
        }

        return $strings;
    }

    private function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (is_string($workbookXml) && is_string($relationshipsXml)) {
            $workbook = $this->parseXml($workbookXml, 'o índice do XLSX');
            $relationships = $this->parseXml($relationshipsXml, 'os vínculos do XLSX');
            $workbookXPath = new DOMXPath($workbook);
            $relationshipXPath = new DOMXPath($relationships);
            $sheets = $workbookXPath->query('//*[local-name()="sheet"]');
            $sheet = $sheets !== false ? $sheets->item(0) : null;
            $relationshipId = $sheet instanceof DOMElement
                ? $sheet->getAttributeNS(
                    'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                    'id',
                )
                : '';

            if ($relationshipId !== '') {
                $relationshipNodes = $relationshipXPath->query(
                    '//*[local-name()="Relationship"]',
                );

                if ($relationshipNodes !== false) {
                    foreach ($relationshipNodes as $relationship) {
                        if (
                            $relationship instanceof DOMElement
                            && $relationship->getAttribute('Id') === $relationshipId
                        ) {
                            $target = $relationship->getAttribute('Target');

                            return $this->normalizeArchivePath(
                                str_starts_with($target, '/')
                                    ? $target
                                    : 'xl/'.$target,
                            );
                        }
                    }
                }
            }
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name) && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1) {
                return $name;
            }
        }

        throw new CardStatementParseException('O XLSX não possui uma planilha legível.');
    }

    private function normalizeArchivePath(string $path): string
    {
        $parts = [];

        foreach (explode('/', ltrim($path, '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function xlsxRows(string $worksheetXml, array $sharedStrings): array
    {
        $document = $this->parseXml($worksheetXml, 'a primeira planilha do XLSX');
        $xpath = new DOMXPath($document);
        $rows = [];

        $rowNodes = $xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');

        if ($rowNodes === false) {
            throw new CardStatementParseException('A primeira planilha do XLSX não pôde ser percorrida.');
        }

        foreach ($rowNodes as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }

            $row = [];

            foreach ($rowNode->childNodes as $cell) {
                if (! $cell instanceof DOMElement || $cell->localName !== 'c') {
                    continue;
                }

                $reference = $cell->getAttribute('r');
                $column = $reference !== ''
                    ? $this->columnIndex($reference)
                    : count($row);
                $type = $cell->getAttribute('t');
                $value = $type === 'inlineStr'
                    ? $this->descendantText($cell, 't')
                    : $this->firstDescendantText($cell, 'v');

                if ($type === 's' && ctype_digit($value)) {
                    $value = $sharedStrings[(int) $value] ?? '';
                }

                $row[$column] = $value;
            }

            if ($row !== []) {
                ksort($row);
                $lastColumn = (int) array_key_last($row);
                $rows[] = array_replace(array_fill(0, $lastColumn + 1, ''), $row);
            }

            if (count($rows) > self::MAX_ROWS + 100) {
                throw new CardStatementParseException(
                    'A fatura ultrapassa o limite de 10.000 lançamentos por arquivo.',
                );
            }
        }

        return $rows;
    }

    private function columnIndex(string $reference): int
    {
        if (preg_match('/^([A-Z]+)/i', $reference, $matches) !== 1) {
            return 0;
        }

        $index = 0;

        foreach (str_split(strtoupper($matches[1])) as $character) {
            $index = ($index * 26) + (ord($character) - 64);
        }

        return max(0, $index - 1);
    }

    /**
     * @return array{array<int, array<int, string>>, string}
     */
    private function readLegacyXls(string $contents): array
    {
        if (str_starts_with($contents, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw new CardStatementParseException(
                'Este XLS usa o formato binário antigo. Abra o arquivo no Excel ou LibreOffice, salve como XLSX ou CSV e tente novamente.',
            );
        }

        $utf8 = $this->toUtf8($contents);
        $prefix = mb_strtolower(ltrim($utf8));

        if (str_starts_with($prefix, '<?xml') || str_contains($prefix, '<workbook')) {
            return [$this->readSpreadsheetXml($utf8), 'xls-xml'];
        }

        if (str_contains($prefix, '<table')) {
            return [$this->readHtmlTable($utf8), 'xls-html'];
        }

        return [$this->readCsv($utf8), 'xls-text'];
    }

    /** @return array<int, array<int, string>> */
    private function readSpreadsheetXml(string $xml): array
    {
        $document = $this->parseXml($xml, 'a planilha XLS');
        $xpath = new DOMXPath($document);
        $tables = $xpath->query('//*[local-name()="Worksheet"]//*[local-name()="Table"]');
        $table = $tables !== false ? $tables->item(0) : null;

        if (! $table instanceof DOMElement) {
            throw new CardStatementParseException('O XLS XML não possui uma planilha legível.');
        }

        $rows = [];

        $rowNodes = $xpath->query('./*[local-name()="Row"]', $table);

        if ($rowNodes === false) {
            throw new CardStatementParseException('As linhas do XLS XML não puderam ser lidas.');
        }

        foreach ($rowNodes as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }

            $row = [];
            $column = 0;

            foreach ($rowNode->childNodes as $cell) {
                if (! $cell instanceof DOMElement || $cell->localName !== 'Cell') {
                    continue;
                }

                foreach ($cell->attributes as $attribute) {
                    $attributeValue = $attribute->nodeValue;

                    if (
                        $attribute->localName === 'Index'
                        && is_string($attributeValue)
                        && ctype_digit($attributeValue)
                    ) {
                        $column = max(0, ((int) $attributeValue) - 1);
                    }
                }

                $row[$column] = $this->firstDescendantText($cell, 'Data');
                $column++;
            }

            if ($row !== []) {
                ksort($row);
                $lastColumn = (int) array_key_last($row);
                $rows[] = array_replace(array_fill(0, $lastColumn + 1, ''), $row);
            }
        }

        return $rows;
    }

    /** @return array<int, array<int, string>> */
    private function readHtmlTable(string $html): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $html) === 1) {
            throw new CardStatementParseException('O XLS HTML contém declarações não permitidas.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new CardStatementParseException('O XLS HTML está corrompido.');
        }

        $table = $document->getElementsByTagName('table')->item(0);

        if (! $table instanceof DOMElement) {
            throw new CardStatementParseException('O XLS HTML não possui uma tabela legível.');
        }

        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $rowNode) {
            $row = [];

            foreach ($rowNode->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array($cell->tagName, ['td', 'th'], true)) {
                    $row[] = $cell->textContent;
                }
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function parseXml(string $xml, string $context): DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw new CardStatementParseException(
                "Não foi possível ler {$context}: declarações externas não são permitidas.",
            );
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new CardStatementParseException("Não foi possível ler {$context}.");
        }

        return $document;
    }

    private function descendantText(DOMNode $node, string $localName): string
    {
        $values = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $values[] = $child->textContent;
            }

            if ($child->hasChildNodes()) {
                $nested = $this->descendantText($child, $localName);

                if ($nested !== '') {
                    $values[] = $nested;
                }
            }
        }

        return implode('', $values);
    }

    private function firstDescendantText(DOMNode $node, string $localName): string
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child->textContent;
            }

            if ($child->hasChildNodes()) {
                $value = $this->firstDescendantText($child, $localName);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function parseDate(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d+(?:[.,]\d+)?$/', $value) === 1) {
            $serial = (float) str_replace(',', '.', $value);

            if ($serial >= 1 && $serial <= 2958465) {
                return (new DateTimeImmutable('1899-12-30'))
                    ->modify('+'.((int) floor($serial)).' days')
                    ->format('Y-m-d');
            }
        }

        $candidate = preg_replace('/[T ]\d{2}:\d{2}.*$/', '', $value) ?? $value;

        foreach (['!d/m/Y', '!d/m/y', '!Y-m-d', '!d-m-Y', '!d.m.Y', '!Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $candidate);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        throw new CardStatementParseException("a data '{$value}' não foi reconhecida.");
    }

    private function parseMoney(string $value): string
    {
        $value = trim(str_replace(["\u{00A0}", '−'], [' ', '-'], $value));
        $negative = str_contains($value, '-')
            || (str_starts_with($value, '(') && str_ends_with($value, ')'));
        $numeric = preg_replace('/[^0-9.,]/u', '', $value) ?? '';

        if ($numeric === '' || preg_match('/\d/', $numeric) !== 1) {
            throw new CardStatementParseException("o valor '{$value}' não foi reconhecido.");
        }

        $lastComma = strrpos($numeric, ',');
        $lastDot = strrpos($numeric, '.');
        $decimalSeparator = null;

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false || $lastDot !== false) {
            $separator = $lastComma !== false ? ',' : '.';
            $position = $lastComma !== false ? $lastComma : $lastDot;
            $decimalDigits = strlen($numeric) - $position - 1;

            if ($decimalDigits > 0 && $decimalDigits <= 2) {
                $decimalSeparator = $separator;
            }
        }

        if ($decimalSeparator !== null) {
            $position = strrpos($numeric, $decimalSeparator);

            if ($position === false) {
                throw new CardStatementParseException("o valor '{$value}' não foi reconhecido.");
            }

            $whole = preg_replace('/\D/', '', substr($numeric, 0, $position)) ?? '';
            $decimal = preg_replace('/\D/', '', substr($numeric, $position + 1)) ?? '';
        } else {
            $whole = preg_replace('/\D/', '', $numeric) ?? '';
            $decimal = '';
        }

        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');
        $money = $whole.'.'.$decimal;

        return $negative && $money !== '0.00' ? '-'.$money : $money;
    }

    /**
     * @param  array<int, CardStatementRow>  $rows
     * @return array<int, CardStatementRow>
     */
    private function normalizePurchaseDirection(array $rows): array
    {
        $negative = 0;
        $positive = 0;

        foreach ($rows as $row) {
            if (str_starts_with($row->amount, '-')) {
                $negative++;
            } elseif ($row->amount !== '0.00') {
                $positive++;
            }
        }

        if ($negative <= $positive) {
            return $rows;
        }

        return array_map(
            fn (CardStatementRow $row): CardStatementRow => new CardStatementRow(
                purchasedOn: $row->purchasedOn,
                description: $row->description,
                amount: $this->invertMoney($row->amount),
                installmentNumber: $row->installmentNumber,
                totalInstallments: $row->totalInstallments,
                externalId: $row->externalId,
                rawData: $row->rawData,
                sourceCategory: $row->sourceCategory,
            ),
            $rows,
        );
    }

    private function isInvoicePayment(string $description): bool
    {
        $normalized = $this->normalizeHeader($description);

        if (in_array($normalized, self::PAYMENT_TITLES, true)) {
            return true;
        }

        foreach (self::PAYMENT_TITLES as $title) {
            if (str_contains($normalized, $title)) {
                return true;
            }
        }

        return false;
    }

    private function invertMoney(string $amount): string
    {
        if ($amount === '0.00') {
            return $amount;
        }

        return str_starts_with($amount, '-') ? substr($amount, 1) : '-'.$amount;
    }

    /**
     * @return array{int|null, int|null}
     */
    private function parseInstallment(
        string $installmentValue,
        string $totalValue,
        string $description,
        int $row,
    ): array {
        $current = null;
        $total = null;
        $combined = trim($installmentValue);

        if ($combined !== '' && preg_match('/(\d{1,3})\s*(?:\/|de|of)\s*(\d{1,3})/iu', $combined, $matches) === 1) {
            $current = (int) $matches[1];
            $total = (int) $matches[2];
        } else {
            $current = $this->nullableInteger($combined);
            $total = $this->nullableInteger($totalValue);
        }

        if ($current === null && $total === null) {
            if (preg_match('/(?:parc(?:ela)?\s*)?(\d{1,3})\s*(?:\/|de)\s*(\d{1,3})(?!\d)/iu', $description, $matches) === 1) {
                $current = (int) $matches[1];
                $total = (int) $matches[2];
            }
        }

        if (($current === null) !== ($total === null)) {
            throw new CardStatementParseException(
                "Linha {$row}: informe tanto o número quanto o total de parcelas.",
            );
        }

        if (
            $current !== null
            && $total !== null
            && ($current < 1 || $total < $current || $total > 999)
        ) {
            throw new CardStatementParseException(
                "Linha {$row}: a indicação de parcela é inválida.",
            );
        }

        return [$current, $total];
    }

    private function nullableInteger(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function toUtf8(string $contents): string
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        $encoding = mb_detect_encoding(
            $contents,
            ['Windows-1252', 'ISO-8859-1'],
            true,
        ) ?: 'Windows-1252';

        $converted = mb_convert_encoding($contents, 'UTF-8', $encoding);

        if ($converted === false) {
            throw new CardStatementParseException(
                'Não foi possível converter a codificação do arquivo para UTF-8.',
            );
        }

        return $converted;
    }

    private function normalizeHeader(string $value): string
    {
        $value = $this->cleanCell($value);
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($transliterated) ? $transliterated : $value;
        $value = mb_strtolower($value);
        $value = str_replace(['r$', 'r $'], 'r', $value);

        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value);
    }

    private function cleanCell(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = str_replace("\u{00A0}", ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** @param array<int, string> $cells */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($this->cleanCell($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, string> $cells */
    private function cell(array $cells, int $column): string
    {
        return $cells[$column] ?? '';
    }

    /** @param array<int, string> $cells */
    private function optionalCell(array $cells, ?int $column): string
    {
        return $column === null ? '' : $this->cell($cells, $column);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $cells
     * @return array<string, string|null>
     */
    private function rawData(array $headers, array $cells): array
    {
        $raw = [];

        foreach ($headers as $column => $header) {
            $key = $header !== '' ? mb_substr($header, 0, 100) : 'coluna_'.($column + 1);
            $baseKey = $key;
            $suffix = 2;

            while (array_key_exists($key, $raw)) {
                $key = $baseKey.'_'.$suffix;
                $suffix++;
            }

            $value = $cells[$column] ?? '';
            $raw[$key] = $value !== '' ? mb_substr($value, 0, 1000) : null;
        }

        return $raw;
    }
}
