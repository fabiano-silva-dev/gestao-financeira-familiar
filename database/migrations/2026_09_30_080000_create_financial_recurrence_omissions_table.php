<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_recurrence_omissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('financial_recurrence_id');
            $table->date('occurrence_date');
            $table->timestamps();

            $table->unique(
                ['financial_recurrence_id', 'occurrence_date'],
                'financial_recurrence_omissions_date_unique',
            );
            $table->foreign(
                ['financial_recurrence_id', 'workspace_id'],
                'financial_recurrence_omissions_recurrence_workspace_fk',
            )
                ->references(['id', 'workspace_id'])
                ->on('financial_recurrences')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_recurrence_omissions');
    }
};
