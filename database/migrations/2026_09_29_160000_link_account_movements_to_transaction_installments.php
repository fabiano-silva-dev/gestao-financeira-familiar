<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('transaction_installment_id')
                ->nullable()
                ->after('financial_transaction_id');

            $table->foreign(
                ['transaction_installment_id', 'workspace_id'],
                'account_movements_installment_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('transaction_installments')
                ->cascadeOnDelete();

            $table->dropUnique('account_movements_transaction_type_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX account_movements_transaction_type_no_installment_unique
             ON account_movements (financial_transaction_id, type)
             WHERE transaction_installment_id IS NULL
               AND financial_transaction_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX account_movements_installment_type_unique
             ON account_movements (transaction_installment_id, type)
             WHERE transaction_installment_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        $movementIds = DB::table('account_movements')
            ->whereNotNull('transaction_installment_id')
            ->pluck('id');

        if ($movementIds->isNotEmpty()) {
            DB::table('bank_statement_entries')
                ->whereIn('account_movement_id', $movementIds)
                ->update([
                    'account_movement_id' => null,
                    'is_reconciled' => false,
                    'reconciled_by' => null,
                    'reconciled_at' => null,
                ]);

            DB::table('account_movements')
                ->whereIn('id', $movementIds)
                ->delete();
        }

        DB::statement('DROP INDEX IF EXISTS account_movements_installment_type_unique');
        DB::statement('DROP INDEX IF EXISTS account_movements_transaction_type_no_installment_unique');

        Schema::table('account_movements', function (Blueprint $table): void {
            $table->dropForeign('account_movements_installment_workspace_fk');
            $table->dropColumn('transaction_installment_id');
            $table->unique(
                ['financial_transaction_id', 'type'],
                'account_movements_transaction_type_unique',
            );
        });
    }
};
