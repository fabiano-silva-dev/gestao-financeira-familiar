<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('type', 20)->default('expense');
            $table->index(
                ['workspace_id', 'type', 'parent_id'],
                'categories_workspace_type_parent_index',
            );
        });

        $legacyGroups = DB::table('categories')
            ->whereNull('parent_id')
            ->get(['id', 'name']);

        foreach ($legacyGroups as $group) {
            $normalizedName = mb_strtolower(trim((string) $group->name));

            $type = match ($normalizedName) {
                'receita', 'receitas' => 'income',
                'despesa', 'despesas' => 'expense',
                default => null,
            };

            if ($type === null) {
                continue;
            }

            DB::table('categories')
                ->where('parent_id', $group->id)
                ->update([
                    'parent_id' => null,
                    'type' => $type,
                ]);

            if (Schema::hasTable('financial_transactions')) {
                DB::table('financial_transactions')
                    ->where('category_id', $group->id)
                    ->update(['category_id' => null]);
            }

            if (Schema::hasTable('financial_recurrences')) {
                DB::table('financial_recurrences')
                    ->where('category_id', $group->id)
                    ->update(['category_id' => null]);
            }

            DB::table('categories')
                ->where('id', $group->id)
                ->delete();
        }

        $incomeCategoryIds = collect();
        $expenseCategoryIds = collect();

        if (Schema::hasTable('financial_transactions')) {
            $incomeCategoryIds = $incomeCategoryIds->merge(
                DB::table('financial_transactions')
                    ->where('type', 'income')
                    ->whereNotNull('category_id')
                    ->pluck('category_id'),
            );

            $expenseCategoryIds = $expenseCategoryIds->merge(
                DB::table('financial_transactions')
                    ->where('type', 'expense')
                    ->whereNotNull('category_id')
                    ->pluck('category_id'),
            );
        }

        if (Schema::hasTable('financial_recurrences')) {
            $incomeCategoryIds = $incomeCategoryIds->merge(
                DB::table('financial_recurrences')
                    ->where('type', 'income')
                    ->whereNotNull('category_id')
                    ->pluck('category_id'),
            );

            $expenseCategoryIds = $expenseCategoryIds->merge(
                DB::table('financial_recurrences')
                    ->where('type', 'expense')
                    ->whereNotNull('category_id')
                    ->pluck('category_id'),
            );
        }

        $incomeOnlyCategoryIds = $incomeCategoryIds
            ->filter()
            ->unique()
            ->diff($expenseCategoryIds->filter()->unique())
            ->values()
            ->all();

        if ($incomeOnlyCategoryIds !== []) {
            DB::table('categories')
                ->whereIn('id', $incomeOnlyCategoryIds)
                ->update(['type' => 'income']);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex('categories_workspace_type_parent_index');
            $table->dropColumn('type');
        });
    }
};
