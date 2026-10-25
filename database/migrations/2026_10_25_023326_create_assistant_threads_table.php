<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Binds an AI SDK conversation (agent_conversations) to the user AND the portfolio it was
     * started in. The SDK's own tables only know the user, and its continue() checks nothing,
     * so this row is what the server checks before a conversation may be continued.
     */
    public function up(): void
    {
        Schema::create('assistant_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('portfolio_id')->constrained()->restrictOnDelete();
            $table->string('conversation_id', 36)->unique();
            $table->timestamps();

            $table->index(['user_id', 'portfolio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_threads');
    }
};
