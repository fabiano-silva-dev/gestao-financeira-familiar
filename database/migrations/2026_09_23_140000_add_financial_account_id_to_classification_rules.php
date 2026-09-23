<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('financial_account_id')
                ->nullable()
                ->after('counterpart_account_id');
            $table->index(['workspace_id', 'financial_account_id']);
            $table->foreign(['financial_account_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classification_rules', function (Blueprint $table) {
            $table->dropForeign(['financial_account_id', 'workspace_id']);
            $table->dropIndex(['workspace_id', 'financial_account_id']);
            $table->dropColumn('financial_account_id');
        });
    }
};
