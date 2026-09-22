<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_period_closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('financial_account_id')->nullable();
            $table->unsignedBigInteger('credit_card_id')->nullable();
            $table->date('period_month');
            $table->string('status', 20);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reopened_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(
                ['workspace_id', 'financial_account_id', 'period_month'],
                'import_period_closures_account_period_unique',
            );
            $table->unique(
                ['workspace_id', 'credit_card_id', 'period_month'],
                'import_period_closures_card_period_unique',
            );
            $table->index(['workspace_id', 'period_month', 'status']);

            $table->foreign(['financial_account_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->cascadeOnDelete();
            $table->foreign(['credit_card_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('credit_cards')
                ->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE import_period_closures
             ADD CONSTRAINT import_period_closures_one_source_check
             CHECK ((financial_account_id IS NULL) <> (credit_card_id IS NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('import_period_closures');
    }
};
