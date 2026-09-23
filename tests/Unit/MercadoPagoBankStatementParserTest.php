<?php

namespace Tests\Unit;

use App\Services\Imports\MercadoPagoBankStatementParser;
use App\Services\Imports\PdfTextExtractor;
use PHPUnit\Framework\TestCase;

class MercadoPagoBankStatementParserTest extends TestCase
{
    public function test_it_parses_mercado_pago_account_statement_with_multiline_descriptions(): void
    {
        $statement = $this->parser()->parseLines([
            'Mercado Pago',
            'EXTRATO DE CONTA',
            'Lidiane Ribeiro Ferro da Silva',
            'CPF/CNPJ: 97775975091 Agência: 1 Conta: 59404058338',
            'Periodo: De 01-06-2026 al 30-06-2026',
            '',
            'DETALHE DOS MOVIMENTOS',
            'Data          Descrição                         ID da operação                   Valor                   Saldo',
            '',
            '              Dinheiro retirado Quitação',
            '01-06-2026                                      161939670992                 R$ 100,00               R$ 100,00',
            '              Imenbui',
            '',
            '              Reembolso de compra',
            '17-06-2026                                      162800502640                  R$ 39,80                R$ 39,80',
            '              Mercado Libre',
            '',
            '18-06-2026    Rendimentos                       1745432447364                  R$ 0,03                R$ 39,83',
        ]);

        $this->assertSame('323', $statement->bankId);
        $this->assertSame('59404058338', $statement->accountId);
        $this->assertSame('BRL', $statement->currency);
        $this->assertSame('2026-06-01', $statement->startOn);
        $this->assertSame('2026-06-30', $statement->endOn);
        $this->assertCount(3, $statement->transactions);
        $this->assertSame(
            'Dinheiro retirado Quitação Imenbui',
            $statement->transactions[0]->description,
        );
        $this->assertSame('100.00', $statement->transactions[0]->amount);
        $this->assertSame('161939670992', $statement->transactions[0]->externalId);
        $this->assertSame(
            'Reembolso de compra Mercado Libre',
            $statement->transactions[1]->description,
        );
        $this->assertSame('39.80', $statement->transactions[1]->amount);
        $this->assertSame('0.03', $statement->transactions[2]->amount);
    }

    public function test_it_preserves_long_multiline_qr_pix_description_and_debit_sign(): void
    {
        $statement = $this->parser()->parseLines([
            'Mercado Pago',
            'EXTRATO DE CONTA',
            'Lidiane Ribeiro Ferro da Silva',
            'CPF/CNPJ: 97775975091 Agência: 1 Conta: 59404058338',
            'Periodo: De 01-07-2026 al 31-07-2026',
            '',
            'DETALHE DOS MOVIMENTOS',
            'Data          Descrição                         ID da operação                   Valor                   Saldo',
            '',
            '              Pagamento com QR Pix',
            '              LAUNCH PAD TECNOLOGIA,',
            '02-07-2026                                      166030563401                 R$ -67,00                 R$ 0,00',
            '              SERVICOS E PAGAMENTOS',
            '              LTDA.',
        ]);

        $this->assertCount(1, $statement->transactions);
        $this->assertSame(
            'Pagamento com QR Pix LAUNCH PAD TECNOLOGIA, SERVICOS E PAGAMENTOS LTDA.',
            $statement->transactions[0]->description,
        );
        $this->assertSame('-67.00', $statement->transactions[0]->amount);
        $this->assertSame('DEBIT', $statement->transactions[0]->transactionType);
        $this->assertSame('166030563401', $statement->transactions[0]->referenceNumber);
        $this->assertSame('2026-07-01', $statement->startOn);
        $this->assertSame('2026-07-31', $statement->endOn);
    }

    private function parser(): MercadoPagoBankStatementParser
    {
        return new MercadoPagoBankStatementParser(new PdfTextExtractor);
    }
}
