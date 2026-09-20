<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_recurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('description', 160);
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('financial_account_id')->nullable();
            $table->unsignedBigInteger('credit_card_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('family_member_id')->nullable();
            $table->string('payment_method', 30);
            $table->string('payee_name', 160)->nullable();
            $table->string('payment_instructions', 500)->nullable();
            $table->string('frequency', 20);
            $table->unsignedSmallInteger('interval')->default(1);
            $table->date('starts_on');
            $table->date('generation_started_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'is_active']);
            $table->index(['workspace_id', 'starts_on', 'ends_on']);

            $table->foreign(['financial_account_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
            $table->foreign(['credit_card_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('credit_cards')
                ->restrictOnDelete();
            $table->foreign(['category_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('categories')
                ->restrictOnDelete();
            $table->foreign(['family_member_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('family_members')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_recurrences');
    }
};
