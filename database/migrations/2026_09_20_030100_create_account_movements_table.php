<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('financial_transaction_id');
            $table->unsignedBigInteger('financial_account_id');
            $table->date('occurred_on');
            $table->string('description', 160);
            $table->decimal('amount', 15, 2);
            $table->string('type', 30);
            $table->boolean('is_reconciled')->default(false);
            $table->timestamps();

            $table->unique(
                ['financial_transaction_id', 'type'],
                'account_movements_transaction_type_unique',
            );
            $table->index(['workspace_id', 'occurred_on']);
            $table->index(['financial_account_id', 'occurred_on']);

            $table->foreign(
                ['financial_transaction_id', 'workspace_id'],
                'account_movements_transaction_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_transactions')
                ->cascadeOnDelete();
            $table->foreign(
                ['financial_account_id', 'workspace_id'],
                'account_movements_account_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_movements');
    }
};
