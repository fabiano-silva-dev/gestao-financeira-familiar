<?php

namespace Tests\Unit;

use App\Services\Imports\CardStatementParseException;
use App\Services\Imports\MercadoPagoCardStatementParser;
use App\Services\Imports\PdfTextExtractor;
use Tests\TestCase;

class MercadoPagoCardStatementParserTest extends TestCase
{
    public function test_it_parses_mercado_pago_credit_card_layout(): void
    {
        $table = $this->parser()->parseLines($this->lines());

        $this->assertSame(['Data', 'Descrição', 'Valor', 'Cartão'], $table[0]);
        $this->assertCount(9, $table);

        $this->assertSame('2026-08-04', $table[1][0]);
        $this->assertSame('Pagamento da fatura de agosto/2026', $table[1][1]);
        $this->assertSame('1.500,00', $table[1][2]);

        $this->assertSame('2026-07-09', $table[3][0]);
        $this->assertSame('Smhigienizacoes Parcela 2 de 3', $table[3][1]);
        $this->assertSame('93,33', $table[3][2]);
        $this->assertSame('Visa · 3736', $table[3][3]);

        $this->assertSame('2026-08-04', $table[4][0]);
        $this->assertSame('INSIDE ESTAMPARIA LTD', $table[4][1]);
        $this->assertSame('85,00', $table[4][2]);

        $this->assertSame('2026-08-05', $table[5][0]);
        $this->assertSame('VEST COMPANHIA Parcela 1 de 6', $table[5][1]);
        $this->assertSame('91,80', $table[5][2]);

        $this->assertSame('2026-08-04', $table[6][0]);
        $this->assertSame('DL*99 RIDE', $table[6][1]);
        $this->assertSame('8,60', $table[6][2]);
        $this->assertSame('Visa · 3759', $table[6][3]);

        $this->assertSame('2026-08-27', $table[7][0]);
        $this->assertSame('DL *99 Ride', $table[7][1]);

        $this->assertSame('2026-09-02', $table[8][0]);
        $this->assertSame('DL *GOOGLE Google One', $table[8][1]);
        $this->assertSame('14,99', $table[8][2]);
    }

    public function test_it_assigns_previous_year_when_purchase_month_is_after_due_date(): void
    {
        $table = $this->parser()->parseLines([
            'Pague sua fatura pelo app Mercado Pago',
            'Vencimento: 08/01/2027',
            'Detalhes de consumo',
            'Cartão Visa [************3736]',
            '15/12     LOJA DO ANO                             R$ 120,00',
            '03/01     FARMACIA                                R$ 40,00',
        ]);

        $this->assertSame('2026-12-15', $table[1][0]);
        $this->assertSame('2027-01-03', $table[2][0]);
    }

    public function test_it_rejects_unknown_pdf_layout(): void
    {
        $this->expectException(CardStatementParseException::class);
        $this->expectExceptionMessage('não corresponde a essa fatura');

        $this->parser()->parseLines([
            'BANRISUL',
            '10/09/2026 Padaria R$ 12,00',
        ]);
    }

    public function test_it_rejects_invoice_without_transactions(): void
    {
        $this->expectException(CardStatementParseException::class);
        $this->expectExceptionMessage('Nenhum lançamento válido');

        $this->parser()->parseLines([
            'Pague sua fatura pelo app Mercado Pago',
            'Vencimento: 08/09/2026',
            'Detalhes de consumo',
            'Parcele a fatura do seu Cartão de Crédito Mercado Pago',
        ]);
    }

    private function parser(): MercadoPagoCardStatementParser
    {
        return new MercadoPagoCardStatementParser(new PdfTextExtractor);
    }

    /** @return array<int, string> */
    private function lines(): array
    {
        return [
            '                                                                                                                       Fabiano Carvalho da Silva',
            '                                                                                                                          Emitida em: 03/09/2026',
            '    Essa é sua fatura de setembro',
            '    Total a pagar                            Vence em                        Limite total',
            '                                             08/09/2026                      R$ 3.800,00',
            '    R$ 1.076,55',
            '                                                                Pague sua fatura pelo app Mercado Pago',
            '                                                       Fabiano Carvalho da Silva',
            '                                                        Vencimento: 08/09/2026',
            'Detalhes de consumo',
            'Movimentações na fatura',
            'Data      Movimentações                                             Valor em R$',
            '04/08     Pagamento da fatura de agosto/2026                        R$ 1.500,00',
            '07/08     Pagamento da fatura de agosto/2026                        R$ 2.108,26',
            'Cartão Visa [************3736]',
            'Data      Movimentações                                             Valor em R$',
            '09/07     Smhigienizacoes                        Parcela 2 de 3       R$ 93,33',
            '04/08     INSIDE ESTAMPARIA LTD                                       R$ 85,00',
            '05/08     VEST COMPANHIA                         Parcela 1 de 6        R$ 91,80',
            'Total                                                               R$ 1.318,39',
            'Cartão Visa [************3759]',
            '04/08     DL*99 RIDE                                                  R$ 8,60',
            '27/08     DL      *99 Ride                                          R$ 11,40',
            '02/09     DL *GOOGLE Google One                     R$ 14,99',
            'Total                                             R$ 1.758,16',
            'Parcele a fatura do seu Cartão de Crédito Mercado Pago',
            '1 + [3]x R$ 298,88',
            'Total: R$ 1.195,52',
            'Seu cartão de crédito',
            'Melhor dia de compra                         03/09/2026',
        ];
    }
}
