<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_card_invoice_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('credit_card_id')->nullable();
        });

        DB::statement(
            'UPDATE credit_card_invoice_payments AS payments
             SET credit_card_id = invoices.credit_card_id
             FROM credit_card_invoices AS invoices
             WHERE payments.credit_card_invoice_id = invoices.id'
        );
        DB::statement(
            'ALTER TABLE credit_card_invoice_payments ALTER COLUMN credit_card_id SET NOT NULL'
        );

        Schema::table('credit_card_invoice_payments', function (Blueprint $table): void {
            $table->dropForeign(
                'credit_card_invoice_payments_credit_card_invoice_id_workspace_id_foreign',
            );
        });

        DB::statement(
            'ALTER TABLE credit_card_invoice_payments ALTER COLUMN credit_card_invoice_id DROP NOT NULL'
        );

        Schema::table('credit_card_invoice_payments', function (Blueprint $table): void {
            $table->index(
                ['credit_card_id', 'paid_on'],
                'card_payments_card_paid_on_index',
            );
            $table->foreign(
                ['credit_card_id', 'workspace_id'],
                'card_payments_card_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('credit_cards')
                ->cascadeOnDelete();
            $table->foreign(
                ['credit_card_invoice_id', 'workspace_id'],
                'card_payments_invoice_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('credit_card_invoices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::statement(
            'UPDATE bank_statement_entries
             SET account_movement_id = NULL,
                 reconciled_by = NULL,
                 reconciled_at = NULL,
                 is_reconciled = FALSE
             WHERE account_movement_id IN (
                 SELECT movements.id
                 FROM account_movements AS movements
                 JOIN credit_card_invoice_payments AS payments
                   ON payments.id = movements.credit_card_invoice_payment_id
                 WHERE payments.credit_card_invoice_id IS NULL
             )'
        );

        DB::table('credit_card_invoice_payments')
            ->whereNull('credit_card_invoice_id')
            ->delete();

        Schema::table('credit_card_invoice_payments', function (Blueprint $table): void {
            $table->dropForeign('card_payments_card_workspace_fk');
            $table->dropForeign('card_payments_invoice_workspace_fk');
            $table->dropIndex('card_payments_card_paid_on_index');
        });

        DB::statement(
            'ALTER TABLE credit_card_invoice_payments ALTER COLUMN credit_card_invoice_id SET NOT NULL'
        );

        Schema::table('credit_card_invoice_payments', function (Blueprint $table): void {
            $table->foreign(
                ['credit_card_invoice_id', 'workspace_id'],
                'credit_card_invoice_payments_credit_card_invoice_id_workspace_id_foreign',
            )
                ->references(['id', 'workspace_id'])
                ->on('credit_card_invoices')
                ->cascadeOnDelete();
            $table->dropColumn('credit_card_id');
        });
    }
};
