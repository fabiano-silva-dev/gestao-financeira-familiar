<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->unique(['id', 'workspace_id']);
        });

        Schema::table('family_members', function (Blueprint $table) {
            $table->unique(['id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropUnique(['id', 'workspace_id']);
        });

        Schema::table('family_members', function (Blueprint $table) {
            $table->dropUnique(['id', 'workspace_id']);
        });
    }
};
