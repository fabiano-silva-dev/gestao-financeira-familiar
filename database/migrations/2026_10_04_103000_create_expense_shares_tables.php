<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('financial_transaction_id');
            $table->decimal('expected_amount', 15, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'financial_transaction_id']);
            $table->foreign(
                ['financial_transaction_id', 'workspace_id'],
                'expense_shares_transaction_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_transactions')
                ->restrictOnDelete();
        });

        Schema::create('expense_share_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('expense_share_id');
            $table->decimal('amount', 15, 2);
            $table->date('received_on');
            $table->unsignedBigInteger('financial_account_id');
            $table->string('origin', 40)->default('bank_reconciliation');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'received_on']);
            $table->foreign(
                ['expense_share_id', 'workspace_id'],
                'expense_share_receipts_share_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('expense_shares')
                ->cascadeOnDelete();
            $table->foreign(
                ['financial_account_id', 'workspace_id'],
                'expense_share_receipts_account_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
        });

        Schema::table('account_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('expense_share_receipt_id')->nullable();
            $table->unique(
                'expense_share_receipt_id',
                'account_movements_expense_share_receipt_unique',
            );
            $table->foreign(
                ['expense_share_receipt_id', 'workspace_id'],
                'account_movements_expense_share_receipt_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('expense_share_receipts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_movements', function (Blueprint $table): void {
            $table->dropForeign('account_movements_expense_share_receipt_workspace_fk');
            $table->dropUnique('account_movements_expense_share_receipt_unique');
            $table->dropColumn('expense_share_receipt_id');
        });

        Schema::dropIfExists('expense_share_receipts');
        Schema::dropIfExists('expense_shares');
    }
};
