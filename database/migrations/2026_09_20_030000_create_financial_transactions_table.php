<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('transaction_date');
            $table->string('description', 160);
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('financial_account_id')->nullable();
            $table->unsignedBigInteger('source_account_id')->nullable();
            $table->unsignedBigInteger('destination_account_id')->nullable();
            $table->unsignedBigInteger('credit_card_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('family_member_id')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('payee_name', 160)->nullable();
            $table->string('payment_instructions', 500)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 20);
            $table->string('origin', 30);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'type', 'transaction_date']);
            $table->index(['workspace_id', 'status', 'transaction_date']);

            $table->foreign(
                ['financial_account_id', 'workspace_id'],
                'fin_tx_account_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
            $table->foreign(
                ['source_account_id', 'workspace_id'],
                'fin_tx_source_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
            $table->foreign(
                ['destination_account_id', 'workspace_id'],
                'fin_tx_destination_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
            $table->foreign(
                ['credit_card_id', 'workspace_id'],
                'fin_tx_card_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('credit_cards')
                ->restrictOnDelete();
            $table->foreign(
                ['category_id', 'workspace_id'],
                'fin_tx_category_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('categories')
                ->restrictOnDelete();
            $table->foreign(
                ['family_member_id', 'workspace_id'],
                'fin_tx_member_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('family_members')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
