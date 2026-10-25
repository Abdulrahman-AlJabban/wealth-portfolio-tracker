<?php

declare(strict_types=1);

use App\Agent\AgentContext;
use App\Agent\AssistantConversations;
use App\Agent\PortfolioAssistant;
use App\Enums\Role;
use App\Models\AssistantThread;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\ConversationStore;

function openChat(User $user, Portfolio $portfolio, ?string $conversationId, string $message = 'Hello'): PortfolioAssistant
{
    return app(AssistantConversations::class)->open(new AgentContext($user, $portfolio, (string) str()->uuid()), $conversationId, $message);
}

beforeEach(function () {
    // No real provider call is possible in this file, including the SDK's title generation.
    Http::preventStrayRequests();
});

test('a new conversation is bound to the user and portfolio before any model call', function () {
    [$viewer, $portfolio] = portfolioWithRole(Role::Viewer);

    $assistant = openChat($viewer, $portfolio, null, 'How is my allocation looking this month?');

    $conversationId = $assistant->currentConversation();
    expect($conversationId)->not->toBeNull();
    $this->assertDatabaseHas('assistant_threads', [
        'conversation_id' => $conversationId,
        'user_id' => $viewer->id,
        'portfolio_id' => $portfolio->id,
    ]);
    // The title is the start of the message, not an extra model call.
    $this->assertDatabaseHas('agent_conversations', ['id' => $conversationId, 'title' => 'How is my allocation looking this month?']);
});

test('a second message with the returned id continues the conversation from server history', function () {
    [$viewer, $portfolio] = portfolioWithRole(Role::Viewer);
    PortfolioAssistant::fake(['You hold nothing yet.', 'Still nothing.'])->preventStrayPrompts();

    $first = openChat($viewer, $portfolio, null, 'What do I hold?');
    $first->prompt('What do I hold?');
    $conversationId = $first->currentConversation();

    $second = openChat($viewer, $portfolio, $conversationId, 'And now?');

    // The earlier turn is replayed from the database, not from anything the client sent.
    $history = collect($second->messages())->map(fn ($message) => [$message->role->value, $message->content])->all();
    expect($history)->toBe([
        ['user', 'What do I hold?'],
        ['assistant', 'You hold nothing yet.'],
    ]);

    $second->prompt('And now?');
    expect(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count())->toBe(4)
        ->and(AssistantThread::count())->toBe(1);
});

test('another user\'s conversation id is rejected', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $editor = memberWithRole($portfolio, Role::Editor)->user;
    $ownersConversation = openChat($owner, $portfolio, null)->currentConversation();

    expect(fn () => openChat($editor, $portfolio, $ownersConversation))->toThrow(ModelNotFoundException::class);
});

test('the same user\'s conversation id from another portfolio is rejected', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $otherPortfolio = Portfolio::create(['name' => 'Second', 'base_currency' => 'USD', 'created_by' => $owner->id]);
    $otherPortfolio->members()->create(['user_id' => $owner->id, 'role' => Role::Owner->value]);
    $conversationInOther = openChat($owner, $otherPortfolio, null)->currentConversation();

    expect(fn () => openChat($owner, $portfolio, $conversationInOther))->toThrow(ModelNotFoundException::class);
});

test('an unknown or made-up conversation id is rejected the same way', function (string $conversationId) {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);

    expect(fn () => openChat($owner, $portfolio, $conversationId))->toThrow(ModelNotFoundException::class);
})->with(['01960000-0000-7000-8000-000000000000', 'not-a-uuid', "' OR 1=1 --"]);

test('a conversation that exists only in the SDK tables, with no thread row, is rejected', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $orphan = app(ConversationStore::class)->storeConversation(User::class, $owner->id, 'Orphan');

    expect(fn () => openChat($owner, $portfolio, $orphan))->toThrow(ModelNotFoundException::class);
});
