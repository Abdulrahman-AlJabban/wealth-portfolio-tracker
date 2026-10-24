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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->decimal('quantity', 24, 8);
            $table->decimal('price', 24, 8);
            $table->decimal('fee', 24, 8)->default(0);
            $table->dateTime('occurred_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('source', 16)->default('user');
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'asset_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
