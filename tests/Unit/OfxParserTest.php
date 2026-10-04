<?php

namespace Tests\Unit;

use App\Services\Imports\OfxParseException;
use App\Services\Imports\OfxParser;
use PHPUnit\Framework\TestCase;

class OfxParserTest extends TestCase
{
    public function test_it_parses_xml_ofx_with_signed_amounts(): void
    {
        $statement = (new OfxParser)->parse(<<<'OFX'
            <?xml version="1.0" encoding="UTF-8"?>
            <OFX>
              <SIGNONMSGSRSV1><SONRS><STATUS><CODE>0</CODE></STATUS></SONRS></SIGNONMSGSRSV1>
              <BANKMSGSRSV1><STMTTRNRS><STMTRS>
                <CURDEF>BRL</CURDEF>
                <BANKACCTFROM><BANKID>748</BANKID><ACCTID>12345-6</ACCTID></BANKACCTFROM>
                <BANKTRANLIST>
                  <DTSTART>20260901000000[-3:BRT]</DTSTART>
                  <DTEND>20260930235959[-3:BRT]</DTEND>
                  <STMTTRN>
                    <TRNTYPE>DEBIT</TRNTYPE>
                    <DTPOSTED>20260915120000[-3:BRT]</DTPOSTED>
                    <TRNAMT>-125.5</TRNAMT>
                    <FITID>xml-001</FITID>
                    <NAME>Pagamento de energia</NAME>
                  </STMTTRN>
                </BANKTRANLIST>
              </STMTRS></STMTTRNRS></BANKMSGSRSV1>
            </OFX>
            OFX);

        $this->assertSame('748', $statement->bankId);
        $this->assertSame('12345-6', $statement->accountId);
        $this->assertSame('BRL', $statement->currency);
        $this->assertSame('2026-09-01', $statement->startOn);
        $this->assertSame('2026-09-30', $statement->endOn);
        $this->assertCount(1, $statement->transactions);
        $this->assertSame('-125.50', $statement->transactions[0]->amount);
        $this->assertSame('2026-09-15', $statement->transactions[0]->occurredOn);
        $this->assertSame('xml-001', $statement->transactions[0]->externalId);
    }

    public function test_it_parses_sgml_ofx_and_escapes_bare_ampersands(): void
    {
        $statement = (new OfxParser)->parse(<<<'OFX'
            OFXHEADER:100
            DATA:OFXSGML
            VERSION:102
            SECURITY:NONE
            ENCODING:USASCII
            CHARSET:1252

            <OFX>
            <BANKMSGSRSV1>
            <STMTTRNRS>
            <STMTRS>
            <CURDEF>BRL
            <BANKACCTFROM>
            <BANKID>748
            <ACCTID>98765-4
            </BANKACCTFROM>
            <BANKTRANLIST>
            <DTSTART>20260901000000[-3:BRT]
            <DTEND>20260930235959[-3:BRT]
            <STMTTRN>
            <TRNTYPE>CREDIT
            <DTPOSTED>20260920120000[-3:BRT]
            <TRNAMT>2500,00
            <FITID>sgml-001
            <NAME>Salário & benefícios
            <MEMO>Crédito em conta
            </STMTTRN>
            </BANKTRANLIST>
            </STMTRS>
            </STMTTRNRS>
            </BANKMSGSRSV1>
            </OFX>
            OFX);

        $this->assertCount(1, $statement->transactions);
        $this->assertSame('2500.00', $statement->transactions[0]->amount);
        $this->assertSame('Salário & benefícios', $statement->transactions[0]->description);
        $this->assertSame('Crédito em conta', $statement->transactions[0]->memo);
    }

    public function test_it_parses_sgml_header_with_already_closed_xml_tags(): void
    {
        $statement = (new OfxParser)->parse(<<<'OFX'
            OFXHEADER:100
            DATA:OFXSGML
            VERSION:100
            SECURITY:NONE
            ENCODING:UTF-8
            CHARSET:NONE
            COMPRESSION:NONE
            OLDFILEUID:NONE
            NEWFILEUID:NONE
            <OFX>
              <SIGNONMSGSRSV1>
                <SONRS>
                  <STATUS>
                    <CODE>0</CODE>
                    <SEVERITY>INFO</SEVERITY>
                  </STATUS>
                  <FI>
                    <ORG>PagSeguro Internet S/A</ORG>
                    <FID>290</FID>
                  </FI>
                </SONRS>
              </SIGNONMSGSRSV1>
              <BANKMSGSRSV1>
                <STMTTRNRS>
                  <STMTRS>
                    <CURDEF>BRL</CURDEF>
                    <BANKACCTFROM>
                      <BANKID>290</BANKID>
                      <ACCTID>6022347-6</ACCTID>
                      <ACCTTYPE>CHECKING</ACCTTYPE>
                    </BANKACCTFROM>
                    <BANKTRANLIST>
                      <DTSTART>20260601000000[-3:BRT]</DTSTART>
                      <DTEND>20260630000000[-3:BRT]</DTEND>
                      <STMTTRN>
                        <TRNTYPE>OUT</TRNTYPE>
                        <DTPOSTED>20260601110006[-3:BRT]</DTPOSTED>
                        <TRNAMT>-120.00</TRNAMT>
                        <FITID>28802d36-c8e8-489f-b648-dbba560f88f7</FITID>
                        <MEMO>Pix enviado - Fabiano Carvalho Da Silva</MEMO>
                      </STMTTRN>
                      <STMTTRN>
                        <TRNTYPE>IN</TRNTYPE>
                        <DTPOSTED>20260603190827[-3:BRT]</DTPOSTED>
                        <TRNAMT>596.00</TRNAMT>
                        <FITID>3e0a92e6-3811-4225-9ff7-fe022165b62c</FITID>
                        <MEMO>Pix recebido - Acsm Servicos Medicos Ltda</MEMO>
                      </STMTTRN>
                    </BANKTRANLIST>
                    <LEDGERBAL>
                      <BALAMT>R$ 0,00</BALAMT>
                      <DTASOF>03/06/2026</DTASOF>
                    </LEDGERBAL>
                  </STMTRS>
                </STMTTRNRS>
              </BANKMSGSRSV1>
            </OFX>
            OFX);

        $this->assertSame('290', $statement->bankId);
        $this->assertSame('6022347-6', $statement->accountId);
        $this->assertSame('BRL', $statement->currency);
        $this->assertSame('2026-06-01', $statement->startOn);
        $this->assertSame('2026-06-30', $statement->endOn);
        $this->assertCount(2, $statement->transactions);
        $this->assertSame('-120.00', $statement->transactions[0]->amount);
        $this->assertSame('Pix enviado - Fabiano Carvalho Da Silva', $statement->transactions[0]->description);
        $this->assertSame('596.00', $statement->transactions[1]->amount);
        $this->assertSame('Pix recebido - Acsm Servicos Medicos Ltda', $statement->transactions[1]->description);
    }

    public function test_it_rejects_external_entity_declarations(): void
    {
        $this->expectException(OfxParseException::class);
        $this->expectExceptionMessage('declaração não permitida');

        (new OfxParser)->parse(<<<'OFX'
            <!DOCTYPE OFX [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
            <OFX><STMTTRN><DTPOSTED>20260920</DTPOSTED><TRNAMT>1.00</TRNAMT></STMTTRN></OFX>
            OFX);
    }

    public function test_it_rejects_files_with_more_than_one_bank_account(): void
    {
        $this->expectException(OfxParseException::class);
        $this->expectExceptionMessage('mais de uma conta');

        (new OfxParser)->parse(<<<'OFX'
            <OFX><BANKMSGSRSV1>
              <STMTTRNRS><STMTRS><BANKACCTFROM><ACCTID>1</ACCTID></BANKACCTFROM><BANKTRANLIST>
                <STMTTRN><DTPOSTED>20260920</DTPOSTED><TRNAMT>1.00</TRNAMT></STMTTRN>
              </BANKTRANLIST></STMTRS></STMTTRNRS>
              <STMTTRNRS><STMTRS><BANKACCTFROM><ACCTID>2</ACCTID></BANKACCTFROM><BANKTRANLIST>
                <STMTTRN><DTPOSTED>20260920</DTPOSTED><TRNAMT>2.00</TRNAMT></STMTTRN>
              </BANKTRANLIST></STMTRS></STMTTRNRS>
            </BANKMSGSRSV1></OFX>
            OFX);
    }
}
