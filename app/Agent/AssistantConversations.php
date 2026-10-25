<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AssistantThread;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Models\Conversation;

/**
 * The only way to get an assistant for a chat. The server, not the browser, holds the history:
 * the client sends a message and at most a conversation id, and the id is checked here.
 */
final class AssistantConversations
{
    /**
     * @param  ConversationStore&ResolvesPendingApprovals  $store  the SDK's database store implements both
     */
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

    /**
     * The transaction the assistant is waiting to have approved, if it is still pending and not expired.
     * Call it after open(), which has already checked the conversation belongs to this user and portfolio.
     *
     * @throws ValidationException when there is no such pending request, or it has expired
     */
    public function pendingApproval(string $conversationId, string $toolCallId): PendingApproval
    {
        $pending = collect($this->store->pendingApprovalsFor($conversationId))
            ->first(fn (PendingApproval $approval): bool => $approval->id === $toolCallId);

        // Already approved, already rejected, or never asked: the same answer, so approving twice fails.
        if ($pending === null) {
            throw ValidationException::withMessages(['tool_call_id' => 'This request is no longer waiting for approval.']);
        }

        // The SDK has no expiry, so we enforce one: the paused turn is the newest message in the conversation.
        $pausedAt = DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'))
            ->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')
            ->value('created_at');

        if ($pausedAt === null || Carbon::parse($pausedAt)->addMinutes((int) config('agent.approval_ttl_minutes'))->isPast()) {
            throw ValidationException::withMessages(['tool_call_id' => 'This request has expired. Ask the assistant again.']);
        }

        return $pending;
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
