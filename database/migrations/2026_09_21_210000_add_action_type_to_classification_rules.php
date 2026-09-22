<?php

use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_rules', function (Blueprint $table) {
            $table->string('action_type', 16)
                ->default(FinancialTransactionType::Expense->value)
                ->after('pattern');
            $table->unsignedBigInteger('counterpart_account_id')
                ->nullable()
                ->after('category_id');
            $table->index(['workspace_id', 'action_type']);
            $table->foreign(['counterpart_account_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->nullOnDelete();
        });

        $incomeCategoryIds = DB::table('categories')
            ->where('type', CategoryType::Income->value)
            ->pluck('id');

        if ($incomeCategoryIds->isNotEmpty()) {
            DB::table('classification_rules')
                ->whereIn('category_id', $incomeCategoryIds)
                ->update([
                    'action_type' => FinancialTransactionType::Income->value,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('classification_rules', function (Blueprint $table) {
            $table->dropForeign(['counterpart_account_id', 'workspace_id']);
            $table->dropIndex(['workspace_id', 'action_type']);
            $table->dropColumn(['action_type', 'counterpart_account_id']);
        });
    }
};
