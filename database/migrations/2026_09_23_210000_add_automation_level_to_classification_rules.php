<?php

use App\Enums\ClassificationRuleAutomationLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_rules', function (Blueprint $table): void {
            $table->string('automation_level', 32)
                ->default(ClassificationRuleAutomationLevel::ClassifyOnly->value)
                ->after('action_type');
            $table->index(['workspace_id', 'automation_level']);
        });

        Schema::table('bank_statement_entries', function (Blueprint $table): void {
            $table->foreignId('matched_classification_rule_id')
                ->nullable()
                ->after('suggested_category_id')
                ->constrained('classification_rules')
                ->nullOnDelete();
            $table->string('automation_level_applied', 32)
                ->nullable()
                ->after('matched_classification_rule_id');
            $table->string('automation_result', 48)
                ->nullable()
                ->after('automation_level_applied');
            $table->string('automation_reason', 255)
                ->nullable()
                ->after('automation_result');
            $table->timestampTz('automation_processed_at')
                ->nullable()
                ->after('automation_reason');

            $table->index(
                ['workspace_id', 'automation_result'],
                'bank_entries_workspace_automation_result_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_entries', function (Blueprint $table): void {
            $table->dropIndex('bank_entries_workspace_automation_result_index');
            $table->dropForeign(['matched_classification_rule_id']);
            $table->dropColumn([
                'matched_classification_rule_id',
                'automation_level_applied',
                'automation_result',
                'automation_reason',
                'automation_processed_at',
            ]);
        });

        Schema::table('classification_rules', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'automation_level']);
            $table->dropColumn('automation_level');
        });
    }
};
