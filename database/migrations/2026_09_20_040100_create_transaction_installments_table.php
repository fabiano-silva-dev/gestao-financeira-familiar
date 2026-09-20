<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('financial_transaction_id');
            $table->unsignedBigInteger('credit_card_invoice_id')->nullable();
            $table->unsignedSmallInteger('installment_number');
            $table->unsignedSmallInteger('total_installments');
            $table->decimal('amount', 15, 2);
            $table->date('competence_month');
            $table->date('due_date');
            $table->date('expected_payment_date')->nullable();
            $table->date('paid_at')->nullable();
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['financial_transaction_id', 'installment_number']);
            $table->index(['workspace_id', 'competence_month']);
            $table->index(['credit_card_invoice_id', 'status']);

            $table->foreign(['financial_transaction_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_transactions')
                ->cascadeOnDelete();
            $table->foreign(['credit_card_invoice_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('credit_card_invoices')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_installments');
    }
};
