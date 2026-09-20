<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('credit_card_invoice_id');
            $table->unsignedBigInteger('financial_account_id');
            $table->date('paid_on');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 30);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'paid_on']);
            $table->index(['credit_card_invoice_id', 'paid_on']);

            $table->foreign(['credit_card_invoice_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('credit_card_invoices')
                ->cascadeOnDelete();
            $table->foreign(['financial_account_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_invoice_payments');
    }
};
