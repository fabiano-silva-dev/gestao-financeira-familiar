<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_statement_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('expense_refund_id')->nullable();
            $table->unique('expense_refund_id', 'card_statement_entries_refund_unique');
            $table->foreign(
                ['expense_refund_id', 'workspace_id'],
                'card_statement_entries_refund_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('expense_refunds')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('card_statement_entries', function (Blueprint $table): void {
            $table->dropForeign('card_statement_entries_refund_workspace_fk');
            $table->dropUnique('card_statement_entries_refund_unique');
            $table->dropColumn('expense_refund_id');
        });
    }
};
