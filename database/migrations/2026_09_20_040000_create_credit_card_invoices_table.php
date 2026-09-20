<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('credit_card_id');
            $table->date('reference_month');
            $table->date('closing_date');
            $table->date('due_date');
            $table->decimal('calculated_amount', 15, 2)->default(0);
            $table->decimal('statement_amount', 15, 2)->nullable();
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('paid_at')->nullable();
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['credit_card_id', 'reference_month']);
            $table->index(['workspace_id', 'status', 'due_date']);

            $table->foreign(['credit_card_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('credit_cards')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_invoices');
    }
};
