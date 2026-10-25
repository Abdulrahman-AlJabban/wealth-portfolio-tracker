<?php

declare(strict_types=1);

namespace App\Agent;

use Closure;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Streaming\Events\Error as StreamError;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Turns an assistant run into our own server-sent events. Shared by the chat endpoint and the
 * approval endpoint, so both speak exactly the same format:
 *
 *   {"type":"conversation","id":"..."}   always first
 *   {"type":"text","text":"..."}
 *   {"type":"tool","name":"..."}
 *   {"type":"approval","id":"...","tool":"...","arguments":{...}}   the run paused for approval
 *   {"type":"error","message":"The assistant is unavailable right now."}
 *   [DONE]                                always last
 */
final class AssistantStream
{
    private const UNAVAILABLE = 'The assistant is unavailable right now.';

    /**
     * @param  Closure(): iterable<mixed>  $run  starts the SDK stream; called inside the response
     */
    public static function response(string $conversationId, Closure $run): StreamedResponse
    {
        return response()->stream(function () use ($conversationId, $run): void {
            self::send(['type' => 'conversation', 'id' => $conversationId]);

            try {
                foreach ($run() as $event) {
                    self::sendEvent($event);
                }
            } catch (Throwable $e) {
                report($e);
                self::send(['type' => 'error', 'message' => self::UNAVAILABLE]);
            }

            echo "data: [DONE]\n\n";
            self::flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private static function sendEvent(mixed $event): void
    {
        if ($event instanceof TextDelta) {
            self::send(['type' => 'text', 'text' => $event->delta]);
        } elseif ($event instanceof ToolCall) {
            self::send(['type' => 'tool', 'name' => $event->toolCall->name]);
        } elseif ($event instanceof ToolApprovalRequest) {
            foreach ($event->pendingApprovals as $approval) {
                /** @var PendingApproval $approval */
                self::send(['type' => 'approval', 'id' => $approval->id, 'tool' => $approval->tool, 'arguments' => $approval->arguments]);
            }
        } elseif ($event instanceof StreamError) {
            // The provider's own error text is never sent to the browser.
            self::send(['type' => 'error', 'message' => self::UNAVAILABLE]);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function send(array $event): void
    {
        echo 'data: '.json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
        self::flush();
    }

    private static function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
