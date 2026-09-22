<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('match_type', 32);
            $table->string('pattern', 255);
            $table->string('payee_name', 160)->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'is_active']);
            $table->index(['workspace_id', 'match_type']);
            $table->foreign(['category_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_rules');
    }
};
