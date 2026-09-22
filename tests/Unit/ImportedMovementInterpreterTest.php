<?php

namespace Tests\Unit;

use App\Enums\AccountMovementType;
use App\Services\Reconciliation\ImportedMovementInterpreter;
use Tests\TestCase;

class ImportedMovementInterpreterTest extends TestCase
{
    public function test_pix_from_third_party_is_not_a_transfer(): void
    {
        $interpreter = new ImportedMovementInterpreter;

        $this->assertFalse($interpreter->isLikelyTransfer(
            'PIX RECEBIDO - USE G CLOSET MODA FEMININA LTDA',
        ));
        $this->assertFalse($interpreter->isLikelyTransfer(
            'PIX ENVIADO - MERCADO PAGO',
        ));
        $this->assertFalse($interpreter->isLikelyTransfer('TED Folha'));
        $this->assertFalse($interpreter->isLikelyTransfer('DOC fornecedor'));
    }

    public function test_explicit_own_account_transfer_language_is_detected(): void
    {
        $interpreter = new ImportedMovementInterpreter;

        $this->assertTrue($interpreter->isLikelyTransfer('Transferência para reserva'));
        $this->assertTrue($interpreter->isLikelyTransfer('PIX TRANSF Nubank Fabiano'));
        $this->assertTrue($interpreter->isLikelyTransfer(
            'PIX RECEBIDO - USE G CLOSET',
            AccountMovementType::TransferIn->value,
        ));
        $this->assertTrue($interpreter->isLikelyTransfer('Dinheiro reservado Despesas Mensais'));
        $this->assertTrue($interpreter->isLikelyTransfer('Dinheiro retirado Despesas Mensais'));
    }

    public function test_mercado_pago_credit_card_payment_is_invoice_settlement(): void
    {
        $interpreter = new ImportedMovementInterpreter;

        $this->assertTrue($interpreter->isInvoicePayment('Pagamento Cartão de crédito'));
        $this->assertTrue($interpreter->isInvoicePayment('Pagamento de fatura Cartão de crédito'));
        $this->assertFalse($interpreter->isInvoicePayment('Pagamento com QR Pix TELEFONICA BRASIL S.A.'));
    }
}
