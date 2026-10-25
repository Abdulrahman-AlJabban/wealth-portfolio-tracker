<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AssistantThread;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;

/**
 * The only way to get an assistant for a chat. The server, not the browser, holds the history:
 * the client sends a message and at most a conversation id, and the id is checked here.
 */
final class AssistantConversations
{
    public function __construct(private ConversationStore $store) {}

    /**
     * Starts a new conversation, or continues one the same user started in the same portfolio.
     * The conversation exists (and its id is known) before any model call, so the endpoint can
     * send the id to the browser first.
     *
     * @throws ModelNotFoundException when the id is unknown, another user's, or another portfolio's
     */
    public function open(AgentContext $context, ?string $conversationId, string $firstMessage): PortfolioAssistant
    {
        $assistant = new PortfolioAssistant($context);

        if ($conversationId === null) {
            $conversationId = $this->start($context, $firstMessage);
        } else {
            $this->assertBelongsTo($context, $conversationId);
        }

        // History is loaded from the server's store; nothing the client sent is replayed.
        return $assistant->continue($conversationId, as: $context->user);
    }

    private function start(AgentContext $context, string $firstMessage): string
    {
        return DB::transaction(function () use ($context, $firstMessage): string {
            $conversationId = $this->store->storeConversation(
                Conversation::participantType($context->user),
                Conversation::participantKey($context->user),
                Str::limit($firstMessage, 50, preserveWords: true),
            );

            AssistantThread::create([
                'user_id' => $context->user->id,
                'portfolio_id' => $context->portfolio->id,
                'conversation_id' => $conversationId,
            ]);

            return $conversationId;
        });
    }

    /**
     * One message for every failure, so the response never reveals whether an id exists.
     *
     * @throws ModelNotFoundException
     */
    private function assertBelongsTo(AgentContext $context, string $conversationId): void
    {
        $owned = AssistantThread::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $context->user->id)
            ->where('portfolio_id', $context->portfolio->id)
            ->exists();

        if (! $owned) {
            throw (new ModelNotFoundException)->setModel(AssistantThread::class);
        }
    }
}
