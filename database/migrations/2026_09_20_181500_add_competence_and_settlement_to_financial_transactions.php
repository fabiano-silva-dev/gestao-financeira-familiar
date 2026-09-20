<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->date('competence_date')->nullable();
            $table->date('settled_on')->nullable();

            $table->index(['workspace_id', 'competence_date']);
            $table->index(['workspace_id', 'settled_on']);
        });

        DB::table('financial_transactions')
            ->whereIn('type', ['income', 'expense'])
            ->update([
                'competence_date' => DB::raw('transaction_date'),
            ]);

        DB::table('financial_transactions')
            ->whereIn('type', ['income', 'expense'])
            ->where('status', 'confirmed')
            ->whereNull('credit_card_id')
            ->update([
                'settled_on' => DB::raw('transaction_date'),
            ]);
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'competence_date']);
            $table->dropIndex(['workspace_id', 'settled_on']);
            $table->dropColumn(['competence_date', 'settled_on']);
        });
    }
};
