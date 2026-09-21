<?php

namespace App\Services\Imports;

use App\Services\Imports\Data\OfxStatement;
use App\Services\Imports\Data\OfxTransaction;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class OfxParser
{
    public function parse(string $contents): OfxStatement
    {
        if (trim($contents) === '') {
            throw new OfxParseException('O arquivo OFX está vazio.');
        }

        $contents = $this->convertToUtf8($contents);

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $contents) === 1) {
            throw new OfxParseException('O arquivo OFX contém uma declaração não permitida.');
        }

        $xml = $this->extractXml($contents);

        if ($this->isSgml($contents, $xml)) {
            $xml = $this->normalizeSgml($xml);
        }

        $document = $this->loadXml($xml);
        $root = $document->documentElement;

        if ($root === null || strtoupper($root->localName) !== 'OFX') {
            throw new OfxParseException('A estrutura principal do arquivo OFX é inválida.');
        }

        $xpath = new DOMXPath($document);
        $statementNodes = $xpath->query('//*[local-name()="STMTRS"]');

        if ($statementNodes === false || $statementNodes->length === 0) {
            throw new OfxParseException('Nenhum extrato de conta bancária foi encontrado no arquivo OFX.');
        }

        if ($statementNodes->length > 1) {
            throw new OfxParseException('O arquivo OFX contém mais de uma conta. Importe uma conta por vez.');
        }

        $statementNode = $statementNodes->item(0);

        if (! $statementNode instanceof DOMNode) {
            throw new OfxParseException('A estrutura do extrato bancário é inválida.');
        }

        $transactionNodes = $xpath->query(
            './/*[local-name()="STMTTRN"]',
            $statementNode,
        );

        if ($transactionNodes === false || $transactionNodes->length === 0) {
            throw new OfxParseException('Nenhum movimento foi encontrado no arquivo OFX.');
        }

        $transactions = [];

        foreach ($transactionNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $postedAt = $this->requiredValue($xpath, $node, 'DTPOSTED');
            $amount = $this->requiredValue($xpath, $node, 'TRNAMT');
            $transactionType = strtoupper(
                $this->optionalValue($xpath, $node, 'TRNTYPE') ?? 'OTHER',
            );
            $name = $this->optionalValue($xpath, $node, 'NAME');
            $memo = $this->optionalValue($xpath, $node, 'MEMO');
            $payee = $this->optionalValue($xpath, $node, 'PAYEEID');
            $description = $name ?? $memo ?? $payee ?? $transactionType;

            $transactions[] = new OfxTransaction(
                occurredOn: $this->parseDate($postedAt),
                amount: $this->parseAmount($amount),
                transactionType: mb_substr($transactionType, 0, 40),
                externalId: $this->limitedValue(
                    $this->optionalValue($xpath, $node, 'FITID'),
                    255,
                ),
                description: mb_substr($description, 0, 255),
                memo: $memo,
                checkNumber: $this->limitedValue(
                    $this->optionalValue($xpath, $node, 'CHECKNUM'),
                    255,
                ),
                referenceNumber: $this->limitedValue(
                    $this->optionalValue($xpath, $node, 'REFNUM'),
                    255,
                ),
            );
        }

        if ($transactions === []) {
            throw new OfxParseException('Nenhum movimento válido foi encontrado no arquivo OFX.');
        }

        return new OfxStatement(
            bankId: $this->optionalValue($xpath, $statementNode, 'BANKID'),
            accountId: $this->limitedValue(
                $this->optionalValue($xpath, $statementNode, 'ACCTID'),
                255,
            ),
            currency: $this->limitedValue(
                $this->optionalValue($xpath, $statementNode, 'CURDEF'),
                10,
            ),
            startOn: $this->optionalDate(
                $this->optionalValue($xpath, $statementNode, 'DTSTART'),
            ),
            endOn: $this->optionalDate(
                $this->optionalValue($xpath, $statementNode, 'DTEND'),
            ),
            transactions: $transactions,
        );
    }

    private function convertToUtf8(string $contents): string
    {
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        $encoding = null;

        if (preg_match('/^CHARSET:\s*([^\r\n]+)/mi', $contents, $matches) === 1) {
            $charset = strtoupper(trim($matches[1]));
            $encoding = match (true) {
                str_contains($charset, '1252') => 'Windows-1252',
                str_contains($charset, '8859-1') => 'ISO-8859-1',
                str_contains($charset, 'UTF-8') => 'UTF-8',
                default => null,
            };
        }

        if ($encoding === null || $encoding === 'UTF-8') {
            $encoding = mb_detect_encoding(
                $contents,
                ['Windows-1252', 'ISO-8859-1'],
                true,
            ) ?: 'Windows-1252';
        }

        $converted = mb_convert_encoding($contents, 'UTF-8', $encoding);

        if (! is_string($converted)) {
            throw new OfxParseException('A codificação do arquivo OFX é inválida.');
        }

        return preg_replace(
            '/(<\?xml[^>]+encoding=["\'])[^"\']+(["\'])/i',
            '$1UTF-8$2',
            $converted,
        ) ?? $converted;
    }

    private function extractXml(string $contents): string
    {
        if (preg_match('/<OFX(?:\s[^>]*)?>/i', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            throw new OfxParseException('O marcador OFX não foi encontrado no arquivo.');
        }

        $offset = $matches[0][1];

        return substr($contents, $offset);
    }

    private function isSgml(string $contents, string $xml): bool
    {
        if (stripos($contents, 'DATA:OFXSGML') !== false) {
            return true;
        }

        return preg_match('/<TRNAMT>[^<\r\n]+(?!<\/TRNAMT>)(?=<|[\r\n]|$)/i', $xml) === 1;
    }

    private function normalizeSgml(string $xml): string
    {
        $xml = preg_replace(
            '/&(?!#\d+;|#x[0-9a-f]+;|[a-z][a-z0-9]+;)/i',
            '&amp;',
            $xml,
        ) ?? $xml;

        return preg_replace_callback(
            '/<(?<tag>[A-Z][A-Z0-9_.:-]*)>(?<value>[^<\r\n]*\S[^<\r\n]*)(?!<\/(?P=tag)>)(?=<|[\r\n]|$)/i',
            static fn (array $matches): string => sprintf(
                '<%1$s>%2$s</%1$s>',
                $matches['tag'],
                $matches['value'],
            ),
            $xml,
        ) ?? $xml;
    }

    private function loadXml(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML(
                $xml,
                LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS | LIBXML_COMPACT,
            );

            if (! $loaded) {
                throw new OfxParseException('O conteúdo do arquivo OFX está malformado.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function requiredValue(
        DOMXPath $xpath,
        DOMNode $context,
        string $name,
    ): string {
        $value = $this->optionalValue($xpath, $context, $name);

        if ($value === null) {
            throw new OfxParseException("O campo {$name} não foi encontrado em um movimento.");
        }

        return $value;
    }

    private function optionalValue(
        DOMXPath $xpath,
        DOMNode $context,
        string $name,
    ): ?string {
        $nodes = $xpath->query('.//*[local-name()="'.$name.'"]', $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMNode
            ? $this->normalizeText($node->textContent)
            : null;
    }

    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value === '' ? null : $value;
    }

    private function optionalDate(?string $value): ?string
    {
        return $value === null ? null : $this->parseDate($value);
    }

    private function parseDate(string $value): string
    {
        if (preg_match('/(?<date>\d{8})/', $value, $matches) !== 1) {
            throw new OfxParseException('Uma data do arquivo OFX é inválida.');
        }

        $date = DateTimeImmutable::createFromFormat('!Ymd', $matches['date']);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new OfxParseException('Uma data do arquivo OFX é inválida.');
        }

        return $date->format('Y-m-d');
    }

    private function parseAmount(string $value): string
    {
        $value = str_replace(["\u{00A0}", ' '], '', trim($value));

        if (str_contains($value, ',') && ! str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        }

        if (preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<decimal>\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new OfxParseException('Um valor monetário do arquivo OFX é inválido.');
        }

        $whole = ltrim($matches['whole'], '0');
        $whole = $whole === '' ? '0' : $whole;
        $decimal = str_pad($matches['decimal'] ?? '', 2, '0');
        $isZero = $whole === '0' && $decimal === '00';
        $sign = $matches['sign'] === '-' && ! $isZero ? '-' : '';

        return "{$sign}{$whole}.{$decimal}";
    }

    private function limitedValue(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
