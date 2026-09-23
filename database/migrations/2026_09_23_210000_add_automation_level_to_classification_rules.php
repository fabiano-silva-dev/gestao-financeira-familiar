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

        $this->addAutomationAuditColumns('bank_statement_entries');
        $this->addAutomationAuditColumns('card_statement_entries');
    }

    public function down(): void
    {
        $this->dropAutomationAuditColumns('card_statement_entries');
        $this->dropAutomationAuditColumns('bank_statement_entries');

        Schema::table('classification_rules', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'automation_level']);
            $table->dropColumn('automation_level');
        });
    }

    private function addAutomationAuditColumns(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $table->unsignedBigInteger('matched_classification_rule_id')
                ->nullable()
                ->after('suggested_category_id');
            $table->string('automation_level_applied', 32)
                ->nullable()
                ->after('matched_classification_rule_id');
            $table->string('automation_result', 48)
                ->nullable()
                ->after('automation_level_applied');
            $table->unsignedSmallInteger('automation_score')
                ->nullable()
                ->after('automation_result');
            $table->string('automation_related_type', 32)
                ->nullable()
                ->after('automation_score');
            $table->unsignedBigInteger('automation_related_id')
                ->nullable()
                ->after('automation_related_type');
            $table->string('automation_reason', 255)
                ->nullable()
                ->after('automation_related_id');
            $table->timestampTz('automation_processed_at')
                ->nullable()
                ->after('automation_reason');

            $table->foreign(
                ['matched_classification_rule_id', 'workspace_id'],
                $tableName.'_automation_rule_workspace_foreign',
            )
                ->references(['id', 'workspace_id'])
                ->on('classification_rules')
                ->restrictOnDelete();

            $table->index(
                ['workspace_id', 'automation_result'],
                $tableName.'_workspace_automation_result_index',
            );
        });
    }

    private function dropAutomationAuditColumns(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $table->dropIndex($tableName.'_workspace_automation_result_index');
            $table->dropForeign($tableName.'_automation_rule_workspace_foreign');
            $table->dropColumn([
                'matched_classification_rule_id',
                'automation_level_applied',
                'automation_result',
                'automation_score',
                'automation_related_type',
                'automation_related_id',
                'automation_reason',
                'automation_processed_at',
            ]);
        });
    }
};
