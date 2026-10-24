<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('portfolio_id')->constrained()->restrictOnDelete();
            $table->string('run_id', 36);
            $table->string('tool', 64);
            $table->json('input');
            $table->string('outcome', 16);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
