<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_statement_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('financial_import_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('card_statement_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('financial_import_id')->nullable(false)->change();
        });
    }
};
