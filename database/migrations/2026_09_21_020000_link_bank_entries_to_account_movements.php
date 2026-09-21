<?php

use App\Enums\AccountMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_movements', function (Blueprint $table): void {
            $table->unique(
                ['id', 'workspace_id'],
                'account_movements_id_workspace_unique',
            );
        });

        Schema::table('bank_statement_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_movement_id')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reconciled_at')->nullable();

            $table->unique(
                ['workspace_id', 'account_movement_id'],
                'bank_entries_workspace_movement_unique',
            );
            $table->foreign(
                ['account_movement_id', 'workspace_id'],
                'bank_entries_movement_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('account_movements')
                ->restrictOnDelete();
        });

        DB::table('account_movements')
            ->whereIn('type', [
                AccountMovementType::TransferOut->value,
                AccountMovementType::TransferIn->value,
            ])
            ->where('is_reconciled', true)
            ->update(['is_reconciled' => false]);
    }

    public function down(): void
    {
        Schema::table('bank_statement_entries', function (Blueprint $table): void {
            $table->dropForeign('bank_entries_movement_workspace_fk');
            $table->dropUnique('bank_entries_workspace_movement_unique');
            $table->dropForeign(['reconciled_by']);
            $table->dropColumn([
                'account_movement_id',
                'reconciled_by',
                'reconciled_at',
            ]);
        });

        Schema::table('account_movements', function (Blueprint $table): void {
            $table->dropUnique('account_movements_id_workspace_unique');
        });
    }
};
