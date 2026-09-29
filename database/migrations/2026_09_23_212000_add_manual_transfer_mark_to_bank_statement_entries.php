<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_entries', function (Blueprint $table): void {
            $table->string('manual_action_type', 32)->nullable();
            $table->unsignedBigInteger('manual_counterpart_account_id')->nullable();

            $table->foreign(
                ['manual_counterpart_account_id', 'workspace_id'],
                'bank_entries_manual_counterpart_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_entries', function (Blueprint $table): void {
            $table->dropForeign('bank_entries_manual_counterpart_workspace_fk');
            $table->dropColumn([
                'manual_action_type',
                'manual_counterpart_account_id',
            ]);
        });
    }
};
