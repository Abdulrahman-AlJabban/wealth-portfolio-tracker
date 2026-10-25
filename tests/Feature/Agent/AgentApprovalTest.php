<?php

declare(strict_types=1);

use App\Agent\PortfolioAssistant;
use App\Enums\Role;
use App\Enums\TransactionSource;
use App\Models\AgentRun;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * @return list<array<string, mixed>|string>
 */
function approvalEvents(TestResponse $response): array
{
    $events = [];

    foreach (explode("\n\n", trim($response->streamedContent())) as $block) {
        $payload = substr($block, strlen('data: '));
        $events[] = $payload === '[DONE]' ? '[DONE]' : json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    return $events;
}

/**
 * The assistant proposes "buy 0.1 BTC at 65000" and pauses. Returns the conversation id.
 */
function proposeBuy(User $user, Portfolio $portfolio): string
{
    PortfolioAssistant::fake([
        new ToolCall('call_1', 'log_transaction', ['asset_symbol' => 'BTC', 'type' => 'buy', 'quantity' => '0.1', 'price' => '65000']),
        'Logged your buy of 0.1 BTC.',
        'Okay, I did not log it.',
    ])->preventStrayPrompts();

    $events = approvalEvents(test()->actingAs($user)->post(route('agent', $portfolio), ['message' => 'Log a buy of 0.1 BTC at 65000']));

    return $events[0]['id'];
}

/**
 * Drops the agent fake so the approval runs through the SDK's REAL resume path.
 *
 * The SDK deliberately skips running approved tools when an agent is faked, so with a fake an
 * "approve" would prove nothing. On the real path the SDK runs the approved tool first (our
 * AssistantTools -> LogTransaction) and only then calls the model, which the suite-wide
 * Http::preventStrayRequests() blocks. So the write is real, and no request leaves the machine.
 */
function useRealGatewayForTheDecision(): void
{
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstance(AiManager::class);
    // The SDK retries the blocked model call with a back-off; skip the waits.
    Sleep::fake();
}

function decide(User $user, Portfolio $portfolio, string $conversationId, string $decision, string $toolCallId = 'call_1'): TestResponse
{
    return test()->actingAs($user)->post(route('agent.approvals', $portfolio), [
        'conversation_id' => $conversationId,
        'tool_call_id' => $toolCallId,
        'decision' => $decision,
    ]);
}

beforeEach(function () {
    config(['ai.default' => 'gemini', 'ai.providers.gemini.key' => 'dummy-test-key', 'agent.enabled' => true]);
    globalAsset('BTC');
});

test('nothing is written before approval, and the proposal is streamed as an approval card', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    PortfolioAssistant::fake([
        new ToolCall('call_1', 'log_transaction', ['asset_symbol' => 'BTC', 'type' => 'buy', 'quantity' => '0.1', 'price' => '65000']),
    ])->preventStrayPrompts();

    $events = approvalEvents($this->actingAs($editor)->post(route('agent', $portfolio), ['message' => 'Log a buy of 0.1 BTC at 65000']));

    expect(collect($events)->firstWhere('type', 'approval'))->toBe([
        'type' => 'approval',
        'id' => 'call_1',
        'tool' => 'log_transaction',
        'arguments' => ['asset_symbol' => 'BTC', 'type' => 'buy', 'quantity' => '0.1', 'price' => '65000'],
    ])
        ->and(end($events))->toBe('[DONE]')
        ->and(Transaction::count())->toBe(0)
        ->and(AgentRun::count())->toBe(0);
});

test('approval writes exactly one row with the agent source', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $conversationId = proposeBuy($editor, $portfolio);
    useRealGatewayForTheDecision();

    decide($editor, $portfolio, $conversationId, 'approve')->assertOk()->streamedContent();

    expect(Transaction::sole()->only(['source', 'quantity', 'created_by']))
        ->toBe(['source' => TransactionSource::Agent, 'quantity' => '0.10000000', 'created_by' => $editor->id])
        ->and(AgentRun::sole()->only(['tool', 'outcome']))->toBe(['tool' => 'log_transaction', 'outcome' => 'ok']);
});

test('rejection writes nothing', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $conversationId = proposeBuy($editor, $portfolio);
    useRealGatewayForTheDecision();

    decide($editor, $portfolio, $conversationId, 'reject')->assertOk()->streamedContent();

    expect(Transaction::count())->toBe(0)->and(AgentRun::count())->toBe(0);
});

test('a viewer cannot approve', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $viewer = memberWithRole($portfolio, Role::Viewer)->user;
    $conversationId = proposeBuy($owner, $portfolio);

    decide($viewer, $portfolio, $conversationId, 'approve')->assertForbidden();

    expect(Transaction::count())->toBe(0);
});

test('someone who was demoted after the request cannot approve it', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $conversationId = proposeBuy($editor, $portfolio);
    $portfolio->members()->where('user_id', $editor->id)->update(['role' => Role::Viewer->value]);

    decide($editor, $portfolio, $conversationId, 'approve')->assertForbidden();

    expect(Transaction::count())->toBe(0);
});

test('another user cannot approve someone else\'s request', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $otherEditor = memberWithRole($portfolio, Role::Editor)->user;
    $conversationId = proposeBuy($editor, $portfolio);

    decide($otherEditor, $portfolio, $conversationId, 'approve')->assertNotFound();

    expect(Transaction::count())->toBe(0);
});

test('approving twice fails and still writes only one row', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $conversationId = proposeBuy($editor, $portfolio);
    useRealGatewayForTheDecision();
    decide($editor, $portfolio, $conversationId, 'approve')->assertOk()->streamedContent();

    decide($editor, $portfolio, $conversationId, 'approve')
        ->assertStatus(422)
        ->assertJsonPath('errors.tool_call_id.0', 'This request is no longer waiting for approval.');

    expect(Transaction::count())->toBe(1);
});

test('an expired request is refused', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $conversationId = proposeBuy($editor, $portfolio);
    $this->travel(11)->minutes();

    decide($editor, $portfolio, $conversationId, 'approve')
        ->assertStatus(422)
        ->assertJsonPath('errors.tool_call_id.0', 'This request has expired. Ask the assistant again.');

    expect(Transaction::count())->toBe(0);
});

test('an unknown tool call id is refused', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $conversationId = proposeBuy($editor, $portfolio);

    decide($editor, $portfolio, $conversationId, 'approve', 'call_999')->assertStatus(422);

    expect(Transaction::count())->toBe(0);
});

test('the approval endpoint validates its input and is throttled', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);

    $this->actingAs($editor)->post(route('agent.approvals', $portfolio), ['decision' => 'maybe'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['conversation_id', 'tool_call_id', 'decision']);

    expect(Route::getRoutes()->getByName('agent.approvals')->gatherMiddleware())->toContain('throttle:20,1');
});
