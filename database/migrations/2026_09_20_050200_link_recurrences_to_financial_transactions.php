<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('financial_recurrence_id')->nullable();
            $table->date('recurrence_occurrence_date')->nullable();

            $table->index(['workspace_id', 'financial_recurrence_id']);
            $table->unique(
                ['financial_recurrence_id', 'recurrence_occurrence_date'],
                'financial_transactions_recurrence_occurrence_unique',
            );

            $table->foreign(
                ['financial_recurrence_id', 'workspace_id'],
                'financial_transactions_recurrence_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_recurrences')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropForeign('financial_transactions_recurrence_workspace_fk');
            $table->dropUnique('financial_transactions_recurrence_occurrence_unique');
            $table->dropIndex(['workspace_id', 'financial_recurrence_id']);
            $table->dropColumn([
                'financial_recurrence_id',
                'recurrence_occurrence_date',
            ]);
        });
    }
};
