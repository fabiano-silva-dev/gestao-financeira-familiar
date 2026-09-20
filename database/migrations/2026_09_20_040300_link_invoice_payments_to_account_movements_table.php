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
            'ALTER TABLE account_movements ALTER COLUMN financial_transaction_id DROP NOT NULL'
        );

        Schema::table('account_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_card_invoice_payment_id')->nullable();
            $table->unique('credit_card_invoice_payment_id');

            $table->foreign(
                ['credit_card_invoice_payment_id', 'workspace_id'],
                'account_movements_invoice_payment_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('credit_card_invoice_payments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('account_movements')
            ->whereNull('financial_transaction_id')
            ->delete();

        Schema::table('account_movements', function (Blueprint $table) {
            $table->dropForeign('account_movements_invoice_payment_workspace_fk');
            $table->dropUnique(['credit_card_invoice_payment_id']);
            $table->dropColumn('credit_card_invoice_payment_id');
        });

        DB::statement(
            'ALTER TABLE account_movements ALTER COLUMN financial_transaction_id SET NOT NULL'
        );
    }
};
