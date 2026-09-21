<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('financial_import_id');
            $table->unsignedBigInteger('financial_account_id');
            $table->string('external_id', 255)->nullable();
            $table->char('deduplication_key', 64);
            $table->date('occurred_on');
            $table->decimal('amount', 15, 2);
            $table->string('transaction_type', 40)->nullable();
            $table->string('description', 255);
            $table->text('memo')->nullable();
            $table->boolean('is_reconciled')->default(false);
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'financial_account_id', 'deduplication_key'],
                'bank_entries_workspace_account_dedupe_unique',
            );
            $table->index(['workspace_id', 'is_reconciled', 'occurred_on']);
            $table->index(['financial_import_id', 'occurred_on']);

            $table->foreign(['financial_import_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_imports')
                ->cascadeOnDelete();
            $table->foreign(['financial_account_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_entries');
    }
};
