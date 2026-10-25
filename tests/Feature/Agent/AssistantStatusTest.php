<?php

declare(strict_types=1);

use App\Agent\AssistantStatus;

// Every test sets the key itself, so the result never depends on the developer's real .env.
beforeEach(function () {
    config([
        'agent.enabled' => true,
        'ai.default' => 'gemini',
        'ai.providers.gemini.key' => null,
    ]);
});

test('is not configured without a key', function (?string $key) {
    config(['ai.providers.gemini.key' => $key]);

    expect(AssistantStatus::isConfigured())->toBeFalse();
})->with([
    'missing' => null,
    'empty' => '',
    'only spaces' => '   ',
]);

test('is configured with a key for the default provider', function () {
    config(['ai.providers.gemini.key' => 'dummy-test-key']);

    expect(AssistantStatus::isConfigured())->toBeTrue();
});

test('is not configured when the assistant is disabled, even with a key', function () {
    config(['ai.providers.gemini.key' => 'dummy-test-key', 'agent.enabled' => false]);

    expect(AssistantStatus::isConfigured())->toBeFalse();
});

test('checks the key of the configured provider, not another one', function () {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.gemini.key' => 'dummy-test-key',
        'ai.providers.anthropic.key' => null,
    ]);

    expect(AssistantStatus::isConfigured())->toBeFalse();
});

test('is not configured when the default provider does not exist', function () {
    config(['ai.default' => 'no-such-provider', 'ai.providers.gemini.key' => 'dummy-test-key']);

    expect(AssistantStatus::isConfigured())->toBeFalse();
});
