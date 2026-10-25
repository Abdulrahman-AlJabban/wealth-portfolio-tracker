<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Agent\AgentContext;
use App\Agent\AssistantConversations;
use App\Agent\AssistantStatus;
use App\Agent\AssistantStream;
use App\Models\Portfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST portfolios/{portfolio}/agent: streams the assistant's answer as server-sent events.
 *
 * Everything that can refuse the request happens before the stream starts, so a refusal is a
 * plain status code (403, 503, 422, 404) and never a half-sent stream.
 */
class AgentController extends Controller
{
    public function __invoke(Request $request, Portfolio $portfolio, AssistantConversations $conversations): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $portfolio);

        if (! AssistantStatus::isConfigured()) {
            return response()->json(['error' => 'not_configured'], 503);
        }

        // Validated by hand so a bad payload is always a JSON 422, even though the browser asks for
        // text/event-stream (Laravel's usual validate() would redirect). Only these two keys are read:
        // the client cannot send history, roles or system messages.
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'min:1', 'max:'.config('agent.max_message_chars')],
            'conversation_id' => ['nullable', 'string', 'max:36'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $message = $validator->validated()['message'];

        // The user comes from the session, never from the request body.
        $context = new AgentContext($request->user(), $portfolio, (string) Str::uuid());

        // Throws ModelNotFoundException (404) for another user's or another portfolio's conversation.
        $assistant = $conversations->open($context, $validator->validated()['conversation_id'] ?? null, $message);

        return AssistantStream::response($assistant->currentConversation(), fn () => $assistant->stream($message));
    }
}
