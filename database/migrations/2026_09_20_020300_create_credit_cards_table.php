<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('institution', 120)->nullable();
            $table->char('last_four', 4);
            $table->unsignedBigInteger('holder_id')->nullable();
            $table->decimal('credit_limit', 15, 2);
            $table->unsignedTinyInteger('closing_day');
            $table->unsignedTinyInteger('due_day');
            $table->unsignedBigInteger('payment_account_id')->nullable();
            $table->string('invoice_payment_method', 30);
            $table->string('payment_instructions', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'is_active']);
            $table->foreign(['holder_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('family_members')
                ->restrictOnDelete();
            $table->foreign(['payment_account_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
