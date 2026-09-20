<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'parent_id']);
            $table->index(['workspace_id', 'is_active']);
            $table->foreign(['parent_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('categories')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
