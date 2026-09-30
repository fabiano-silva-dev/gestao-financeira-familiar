<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE credit_card_invoice_payments ALTER COLUMN financial_account_id DROP NOT NULL',
        );

        Schema::table('credit_card_invoice_payments', function (Blueprint $table): void {
            $table->boolean('is_advance')->default(false);
        });

        Schema::table('card_statement_entries', function (Blueprint $table): void {
            $table->boolean('is_payment')->default(false);
            $table->unsignedBigInteger('credit_card_invoice_payment_id')->nullable();
            $table->index(
                ['workspace_id', 'credit_card_invoice_payment_id'],
                'card_statement_entries_payment_index',
            );
            $table->foreign(
                ['credit_card_invoice_payment_id', 'workspace_id'],
                'card_statement_entries_payment_workspace_foreign',
            )
                ->references(['id', 'workspace_id'])
                ->on('credit_card_invoice_payments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('card_statement_entries', function (Blueprint $table): void {
            $table->dropForeign('card_statement_entries_payment_workspace_foreign');
            $table->dropIndex('card_statement_entries_payment_index');
            $table->dropColumn(['credit_card_invoice_payment_id', 'is_payment']);
        });

        Schema::table('credit_card_invoice_payments', function (Blueprint $table): void {
            $table->dropColumn('is_advance');
        });

        DB::table('credit_card_invoice_payments')->whereNull('financial_account_id')->delete();

        DB::statement(
            'ALTER TABLE credit_card_invoice_payments ALTER COLUMN financial_account_id SET NOT NULL',
        );
    }
};
