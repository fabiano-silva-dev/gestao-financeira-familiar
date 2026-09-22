<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('financial_transaction_id');
            $table->decimal('amount', 15, 2);
            $table->date('refunded_on');
            $table->string('destination_type', 20);
            $table->unsignedBigInteger('destination_account_id')->nullable();
            $table->unsignedBigInteger('credit_card_id')->nullable();
            $table->unsignedBigInteger('credit_card_invoice_id')->nullable();
            $table->string('status', 20);
            $table->string('origin', 30);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'financial_transaction_id', 'status']);
            $table->index(['workspace_id', 'refunded_on']);
            $table->index(['credit_card_invoice_id', 'status']);

            $table->foreign(
                ['financial_transaction_id', 'workspace_id'],
                'expense_refunds_transaction_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_transactions')
                ->restrictOnDelete();
            $table->foreign(
                ['destination_account_id', 'workspace_id'],
                'expense_refunds_account_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
            $table->foreign(
                ['credit_card_id', 'workspace_id'],
                'expense_refunds_card_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('credit_cards')
                ->restrictOnDelete();
            $table->foreign(
                ['credit_card_invoice_id', 'workspace_id'],
                'expense_refunds_invoice_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('credit_card_invoices')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE expense_refunds
             ADD CONSTRAINT expense_refunds_destination_check
             CHECK (
                 (
                     destination_type = 'account'
                     AND destination_account_id IS NOT NULL
                     AND credit_card_id IS NULL
                     AND credit_card_invoice_id IS NULL
                 )
                 OR
                 (
                     destination_type = 'credit_card'
                     AND destination_account_id IS NULL
                     AND credit_card_id IS NOT NULL
                     AND credit_card_invoice_id IS NOT NULL
                 )
             )"
        );

        Schema::table('account_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('expense_refund_id')->nullable();
            $table->unique('expense_refund_id', 'account_movements_refund_unique');
            $table->foreign(
                ['expense_refund_id', 'workspace_id'],
                'account_movements_refund_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('expense_refunds')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_movements', function (Blueprint $table): void {
            $table->dropForeign('account_movements_refund_workspace_fk');
            $table->dropUnique('account_movements_refund_unique');
            $table->dropColumn('expense_refund_id');
        });

        Schema::dropIfExists('expense_refunds');
    }
};
