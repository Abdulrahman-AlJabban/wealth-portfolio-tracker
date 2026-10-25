<?php

declare(strict_types=1);

/*
 * The in-app portfolio assistant. The AI provider, model and keys live in config/ai.php.
 */
return [

    // Turn the assistant off without removing the key (the panel then says it is not configured).
    'enabled' => env('AGENT_ENABLED', true),

    // Longest user message the endpoint accepts.
    'max_message_chars' => 4000,

    // Used only if conversations fall back to client-side history (ai_agent_tasks.md Task 7).
    'max_history' => 20,

    // Most model-and-tool round trips allowed for one user message.
    'max_tool_rounds' => 6,

    // A transaction the assistant proposes must be approved within this many minutes.
    'approval_ttl_minutes' => 10,

    // Providers from config/ai.php to try, in order, when the default one is overloaded, rate limited
    // or unreachable. Comma-separated, for example AI_FAILOVER=gemini-fallback. Empty means no failover.
    'failover' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_FAILOVER', ''))))),

];
