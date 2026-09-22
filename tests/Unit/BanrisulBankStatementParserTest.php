<?php

namespace Tests\Unit;

use App\Services\Imports\BankStatementParseException;
use App\Services\Imports\BanrisulBankStatementParser;
use App\Services\Imports\PdfTextExtractor;
use PHPUnit\Framework\TestCase;

class BanrisulBankStatementParserTest extends TestCase
{
    public function test_it_parses_banrisul_current_account_layout(): void
    {
        $statement = $this->parser()->parseLines($this->lines());

        $this->assertSame('041', $statement->bankId);
        $this->assertSame('0600685509', $statement->accountId);
        $this->assertSame('2025-11-03', $statement->startOn);
        $this->assertSame('2025-11-03', $statement->endOn);
        $this->assertCount(3, $statement->transactions);
        $this->assertSame('10000.00', $statement->transactions[0]->amount);
        $this->assertSame('CREDIT', $statement->transactions[0]->transactionType);
        $this->assertSame('RESGATE CDB', $statement->transactions[0]->description);
        $this->assertSame('-354.00', $statement->transactions[1]->amount);
        $this->assertSame('PIX ENVIADO - VOLMIR JAQUES CECHIN', $statement->transactions[1]->description);
        $this->assertSame('VOLMIR JAQUES CECHIN', $statement->transactions[1]->memo);
        $this->assertSame('065918', $statement->transactions[1]->referenceNumber);
        $this->assertSame('-560.00', $statement->transactions[2]->amount);
    }

    public function test_it_parses_compact_personal_account_layout(): void
    {
        $statement = $this->parser()->parseLines([
            'B A N R I S U L',
            'AGENCIA: 0377 - CAMOBI',
            'CONTA..: 35.113679.0-6',
            'NOME...: TITULAR DA CONTA',
            'PERIODO: AGOSTO/2026',
            'DIA HISTORICO           DOCUMENTO        V A L O R',
            '---------- MOVIMENTOS DA CONTA CORRENTE ----------',
            '     SALDO ANT EM 31/07/2026               347,02-',
            '++   MOVIMENTOS AGO/2026',
            '03   CRED TRANSFER       299554          2.000,00',
            '     IOF ADICIONAL       000000              5,72-',
            '     SALDO NA DATA                       3.694,10',
            '04   REND CDB AUT        0000RC              0,01',
            '     PIX RECEBIDO        DC22AW            200,00',
            '      NOME: MARIA DE TESTE',
            '     PIX OPEN FINANCE    5GG9DI           3.000,00-',
            '      NOME: TITULAR DA CONTA',
            '14   PIX RECEBIDO        354236           3.500,00',
            "\f    PIX                 402398           3.500,00-",
            '     NOME: TITULAR DA CONTA',
        ]);

        $this->assertSame('3511367906', $statement->accountId);
        $this->assertSame('2026-08-03', $statement->startOn);
        $this->assertSame('2026-08-14', $statement->endOn);
        $this->assertCount(7, $statement->transactions);
        $this->assertSame('2000.00', $statement->transactions[0]->amount);
        $this->assertSame('CREDIT', $statement->transactions[0]->transactionType);
        $this->assertSame('-5.72', $statement->transactions[1]->amount);
        $this->assertSame('0000RC', $statement->transactions[2]->referenceNumber);
        $this->assertSame('PIX RECEBIDO - MARIA DE TESTE', $statement->transactions[3]->description);
        $this->assertSame('DC22AW', $statement->transactions[3]->referenceNumber);
        $this->assertSame('-3000.00', $statement->transactions[4]->amount);
        $this->assertSame('PIX OPEN FINANCE - TITULAR DA CONTA', $statement->transactions[4]->description);
        $this->assertSame('5GG9DI', $statement->transactions[4]->referenceNumber);
        $this->assertSame('2026-08-14', $statement->transactions[6]->occurredOn);
        $this->assertSame('-3500.00', $statement->transactions[6]->amount);
        $this->assertSame('402398', $statement->transactions[6]->referenceNumber);
    }

    public function test_it_rejects_unknown_pdf_layout(): void
    {
        $this->expectException(BankStatementParseException::class);

        $this->parser()->parseLines([
            'Extrato Nubank',
            '10/09/2026 Padaria 12,00',
        ]);
    }

    private function parser(): BanrisulBankStatementParser
    {
        return new BanrisulBankStatementParser(new PdfTextExtractor);
    }

    /** @return array<int, string> */
    private function lines(): array
    {
        return [
            'B A N R I S U L                                                       04/12/2025',
            'AGENCIA: 0613 - FAXINAL DO SOTURNO',
            'CONTA..: 06.006855.0-9',
            'NOME...: SPECIATTA CONGELADOS LTDA',
            'DIA HISTORICO                                          DOCUMENTO       V A L O R',
            '------------------------- MOVIMENTOS DA CONTA CORRENTE -------------------------',
            '     SALDO ANT EM 31/10/2025                          54.827,46-',
            '++   MOVIMENTOS NOV/2025',
            '03   RESGATE CDB                             000006   10.000,00',
            '     PIX ENVIADO                             065918      354,00-',
            '      NOME: VOLMIR JAQUES CECHIN',
            '     PIX ENVIADO                             079737      560,00-',
            '      NOME: VANDERLEI GOULART DE MATTOS',
        ];
    }
}
