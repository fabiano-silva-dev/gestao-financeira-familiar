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
