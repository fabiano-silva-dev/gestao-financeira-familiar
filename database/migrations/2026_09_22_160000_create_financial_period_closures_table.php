<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_period_closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('financial_account_id')->nullable();
            $table->unsignedBigInteger('credit_card_id')->nullable();
            $table->date('reference_month');
            $table->string('status', 30)->default('closed');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at');
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(
                ['workspace_id', 'financial_account_id', 'reference_month'],
                'period_closures_workspace_account_month_unique',
            );
            $table->unique(
                ['workspace_id', 'credit_card_id', 'reference_month'],
                'period_closures_workspace_card_month_unique',
            );
            $table->index(['workspace_id', 'reference_month']);

            $table->foreign(['financial_account_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
            $table->foreign(['credit_card_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('credit_cards')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_period_closures');
    }
};
