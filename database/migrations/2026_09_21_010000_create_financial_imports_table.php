<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('financial_account_id')->nullable();
            $table->unsignedBigInteger('credit_card_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->string('status', 20);
            $table->string('source_filename', 255);
            $table->string('stored_path', 500)->nullable();
            $table->char('file_hash', 64);
            $table->char('deduplication_key', 64);
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('imported_records')->default(0);
            $table->unsignedInteger('duplicate_records')->default(0);
            $table->date('statement_start_on')->nullable();
            $table->date('statement_end_on')->nullable();
            $table->string('external_account_identifier', 255)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(
                ['workspace_id', 'type', 'deduplication_key'],
                'financial_imports_workspace_type_dedupe_unique',
            );
            $table->index(['workspace_id', 'type', 'created_at']);
            $table->index(['workspace_id', 'status']);

            $table->foreign(['financial_account_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
            $table->foreign(['credit_card_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('credit_cards')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_imports');
    }
};
