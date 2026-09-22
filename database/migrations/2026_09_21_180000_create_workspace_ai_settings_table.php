<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->text('gemini_api_key')->nullable();
            $table->text('groq_api_key')->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->timestamps();

            $table->unique('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_ai_settings');
    }
};
