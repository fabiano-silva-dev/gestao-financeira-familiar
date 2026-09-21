<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_statement_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('transaction_installment_id')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reconciled_at')->nullable();

            $table->unique(
                ['workspace_id', 'transaction_installment_id'],
                'card_statement_entries_workspace_installment_unique',
            );
            $table->foreign(
                ['transaction_installment_id', 'workspace_id'],
                'card_statement_entries_installment_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('transaction_installments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('card_statement_entries', function (Blueprint $table): void {
            $table->dropForeign('card_statement_entries_installment_workspace_fk');
            $table->dropUnique('card_statement_entries_workspace_installment_unique');
            $table->dropForeign(['reconciled_by']);
            $table->dropColumn([
                'transaction_installment_id',
                'reconciled_by',
                'reconciled_at',
            ]);
        });
    }
};
