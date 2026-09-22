<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_entries', function (Blueprint $table): void {
            $table->boolean('is_ignored')->default(false);
            $table->foreignId('ignored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('ignored_at')->nullable();
            $table->string('suggested_payee_name', 160)->nullable();
            $table->unsignedBigInteger('suggested_category_id')->nullable();

            $table->index(
                ['workspace_id', 'is_reconciled', 'is_ignored', 'occurred_on'],
                'bank_entries_workspace_pending_ignored_index',
            );
            $table->foreign(
                ['suggested_category_id', 'workspace_id'],
                'bank_entries_suggested_category_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('categories')
                ->nullOnDelete();
        });

        Schema::table('card_statement_entries', function (Blueprint $table): void {
            $table->boolean('is_ignored')->default(false);
            $table->foreignId('ignored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('ignored_at')->nullable();
            $table->string('suggested_payee_name', 160)->nullable();
            $table->unsignedBigInteger('suggested_category_id')->nullable();

            $table->index(
                ['workspace_id', 'is_reconciled', 'is_ignored'],
                'card_entries_workspace_pending_ignored_index',
            );
            $table->foreign(
                ['suggested_category_id', 'workspace_id'],
                'card_entries_suggested_category_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_entries', function (Blueprint $table): void {
            $table->dropForeign('bank_entries_suggested_category_workspace_fk');
            $table->dropIndex('bank_entries_workspace_pending_ignored_index');
            $table->dropForeign(['ignored_by']);
            $table->dropColumn([
                'is_ignored',
                'ignored_by',
                'ignored_at',
                'suggested_payee_name',
                'suggested_category_id',
            ]);
        });

        Schema::table('card_statement_entries', function (Blueprint $table): void {
            $table->dropForeign('card_entries_suggested_category_workspace_fk');
            $table->dropIndex('card_entries_workspace_pending_ignored_index');
            $table->dropForeign(['ignored_by']);
            $table->dropColumn([
                'is_ignored',
                'ignored_by',
                'ignored_at',
                'suggested_payee_name',
                'suggested_category_id',
            ]);
        });
    }
};
