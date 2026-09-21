<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_statement_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('financial_import_id');
            $table->unsignedBigInteger('credit_card_id');
            $table->unsignedBigInteger('credit_card_invoice_id');
            $table->date('purchased_on');
            $table->string('description', 255);
            $table->decimal('amount', 15, 2);
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('total_installments')->nullable();
            $table->string('external_id', 255)->nullable();
            $table->char('deduplication_key', 64);
            $table->jsonb('raw_data')->nullable();
            $table->boolean('is_reconciled')->default(false);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(
                ['workspace_id', 'credit_card_id', 'deduplication_key'],
                'card_statement_entries_workspace_card_dedupe_unique',
            );
            $table->index(
                ['credit_card_invoice_id', 'is_reconciled', 'purchased_on'],
                'card_statement_entries_invoice_pending_index',
            );
            $table->index(['workspace_id', 'is_reconciled']);

            $table->foreign(['financial_import_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_imports')
                ->cascadeOnDelete();
            $table->foreign(['credit_card_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('credit_cards')
                ->restrictOnDelete();
            $table->foreign(['credit_card_invoice_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('credit_card_invoices')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_statement_entries');
    }
};
