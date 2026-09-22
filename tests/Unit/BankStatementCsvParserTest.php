<?php

namespace Tests\Unit;

use App\Services\Imports\BankStatementCsvParser;
use App\Services\Imports\BankStatementParseException;
use PHPUnit\Framework\TestCase;

class BankStatementCsvParserTest extends TestCase
{
    public function test_it_parses_brazilian_csv_with_signed_amounts(): void
    {
        $statement = (new BankStatementCsvParser)->parse(<<<'CSV'
            Data;Histórico;Valor;Documento
            10/09/2026;PIX Enviado;-50,90;pix-1
            11/09/2026;Salário;2.500,00;sal-1
            CSV);

        $this->assertSame('2026-09-10', $statement->startOn);
        $this->assertSame('2026-09-11', $statement->endOn);
        $this->assertCount(2, $statement->transactions);
        $this->assertSame('-50.90', $statement->transactions[0]->amount);
        $this->assertSame('DEBIT', $statement->transactions[0]->transactionType);
        $this->assertSame('pix-1', $statement->transactions[0]->externalId);
        $this->assertSame('2500.00', $statement->transactions[1]->amount);
        $this->assertSame('CREDIT', $statement->transactions[1]->transactionType);
    }

    public function test_it_uses_debit_and_credit_columns(): void
    {
        $statement = (new BankStatementCsvParser)->parse(<<<'CSV'
            Data,Descrição,Débito,Crédito
            21/09/2026,Conta de luz,89.90,
            22/09/2026,Transferência recebida,,1500.00
            CSV);

        $this->assertCount(2, $statement->transactions);
        $this->assertSame('-89.90', $statement->transactions[0]->amount);
        $this->assertSame('1500.00', $statement->transactions[1]->amount);
    }

    public function test_it_parses_mercado_pago_account_statement(): void
    {
        $statement = (new BankStatementCsvParser)->parse(<<<'CSV'
            INITIAL_BALANCE;CREDITS;DEBITS;FINAL_BALANCE
            1,52;25.441,34;-25.442,86;0,00

            RELEASE_DATE;TRANSACTION_TYPE;REFERENCE_ID;TRANSACTION_NET_AMOUNT;PARTIAL_BALANCE
            01-07-2026;Dinheiro reservado Despesas Mensais ;166611940878;-1,52;0,00
            02-07-2026;Pix recebido FABIANO CARVALHO DA SILVA;166801941210;500,00;500,00
            02-07-2026;Pagamento Cartão de crédito;166802160620;-500,00;0,00
            03-07-2026;Pix recebido FABIANO CARVALHO DA SILVA;166959411240;1.500,00;1.500,00
            08-07-2026;Rendimentos ;1746447981172;0,36;861,36
            CSV);

        $this->assertSame('2026-07-01', $statement->startOn);
        $this->assertSame('2026-07-08', $statement->endOn);
        $this->assertCount(5, $statement->transactions);
        $this->assertSame('2026-07-01', $statement->transactions[0]->occurredOn);
        $this->assertSame('Dinheiro reservado Despesas Mensais', $statement->transactions[0]->description);
        $this->assertSame('-1.52', $statement->transactions[0]->amount);
        $this->assertSame('DEBIT', $statement->transactions[0]->transactionType);
        $this->assertSame('166611940878', $statement->transactions[0]->externalId);
        $this->assertSame('166611940878', $statement->transactions[0]->referenceNumber);
        $this->assertSame('1500.00', $statement->transactions[3]->amount);
        $this->assertSame('CREDIT', $statement->transactions[3]->transactionType);
        $this->assertSame('0.36', $statement->transactions[4]->amount);
        $this->assertSame('1746447981172', $statement->transactions[4]->externalId);
    }

    public function test_it_rejects_csv_without_required_columns(): void
    {
        $this->expectException(BankStatementParseException::class);

        (new BankStatementCsvParser)->parse("Nome;Valor\nPadaria;10,00\n");
    }
}
