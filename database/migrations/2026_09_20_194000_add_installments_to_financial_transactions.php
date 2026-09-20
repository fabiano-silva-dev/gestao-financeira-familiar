<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('installment_count')->nullable();

            $table->index(['workspace_id', 'parent_transaction_id']);
            $table->unique(
                ['parent_transaction_id', 'installment_number'],
                'financial_transactions_installment_unique',
            );

            $table->foreign(
                ['parent_transaction_id', 'workspace_id'],
                'fin_tx_parent_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_transactions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropForeign('fin_tx_parent_workspace_fk');
            $table->dropUnique('financial_transactions_installment_unique');
            $table->dropIndex(['workspace_id', 'parent_transaction_id']);
            $table->dropColumn([
                'parent_transaction_id',
                'installment_number',
                'installment_count',
            ]);
        });
    }
};
