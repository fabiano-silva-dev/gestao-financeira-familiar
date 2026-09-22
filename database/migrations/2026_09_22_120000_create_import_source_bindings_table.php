<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_source_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('institution', 80)->default('unknown');
            $table->string('document_type', 50);
            $table->string('identifier_type', 50);
            $table->string('identifier_value', 255);
            $table->unsignedBigInteger('financial_account_id')->nullable();
            $table->unsignedBigInteger('credit_card_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'document_type', 'institution', 'identifier_type', 'identifier_value'],
                'import_source_bindings_identity_unique',
            );

            $table->foreign(['financial_account_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->cascadeOnDelete();
            $table->foreign(['credit_card_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('credit_cards')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_source_bindings');
    }
};
