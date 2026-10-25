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
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST portfolios/{portfolio}/agent/approvals: the user approves or rejects a transaction the
 * assistant proposed, and the paused run continues as a stream.
 *
 * Every check happens before streaming, so a refusal is a plain status code. Approving runs the
 * tool now, as this user, through LogTransaction, which checks the role again at that moment.
 */
class AgentApprovalController extends Controller
{
    public function __invoke(Request $request, Portfolio $portfolio, AssistantConversations $conversations): StreamedResponse|JsonResponse
    {
        // A Viewer, or someone demoted since the request was made, cannot approve.
        Gate::authorize('write', $portfolio);

        if (! AssistantStatus::isConfigured()) {
            return response()->json(['error' => 'not_configured'], 503);
        }

        $validator = Validator::make($request->all(), [
            'conversation_id' => ['required', 'string', 'max:36'],
            'tool_call_id' => ['required', 'string', 'max:255'],
            'decision' => ['required', 'string', 'in:approve,reject'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        ['conversation_id' => $conversationId, 'tool_call_id' => $toolCallId, 'decision' => $choice] = $validator->validated();

        $context = new AgentContext($request->user(), $portfolio, (string) Str::uuid());

        // 404 for another user's or another portfolio's conversation.
        $assistant = $conversations->open($context, $conversationId, '');

        try {
            $conversations->pendingApproval($conversationId, $toolCallId);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $decision = $choice === 'approve'
            ? Decision::approve()
            : Decision::reject('The user rejected this transaction. Nothing was logged.');

        return AssistantStream::response(
            $conversationId,
            fn () => $assistant->stream(Decisions::from([$toolCallId => $decision])),
        );
    }
}
