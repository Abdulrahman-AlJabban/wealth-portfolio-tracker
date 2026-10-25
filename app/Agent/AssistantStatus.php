<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Whether the assistant can run: it is enabled and the configured provider has credentials.
 * It only checks that a key is present; it never reads it out, logs it or calls the provider.
 */
final class AssistantStatus
{
    public static function isConfigured(): bool
    {
        if (! config('agent.enabled')) {
            return false;
        }

        $provider = config('ai.default');

        if (! is_string($provider) || config("ai.providers.{$provider}") === null) {
            return false;
        }

        // Every provider block in config/ai.php keeps its credential under "key".
        return filled(config("ai.providers.{$provider}.key"));
    }
}
