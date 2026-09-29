<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_period_closures', function (Blueprint $table): void {
            $table->foreignId('coverage_confirmed_by')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('coverage_confirmed_at')
                ->nullable()
                ->after('coverage_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('import_period_closures', function (Blueprint $table): void {
            $table->dropForeign(['coverage_confirmed_by']);
            $table->dropColumn(['coverage_confirmed_by', 'coverage_confirmed_at']);
        });
    }
};
