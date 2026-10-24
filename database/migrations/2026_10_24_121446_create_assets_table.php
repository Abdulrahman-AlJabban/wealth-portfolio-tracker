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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->string('symbol', 16)->nullable();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->char('currency', 3)->default('USD');
            $table->string('price_source', 16);
            $table->timestamps();

            $table->index(['portfolio_id', 'symbol']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
