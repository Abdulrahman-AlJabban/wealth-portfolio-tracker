import { router } from '@inertiajs/react';
import { FormEvent, KeyboardEvent, useEffect, useRef, useState } from 'react';

type ChatMessage = {
    role: 'user' | 'assistant';
    content: string;
    tools: string[];
};

/** A transaction the assistant proposed and is waiting for the user to approve or reject. */
type PendingApproval = {
    id: string;
    arguments: Record<string, string>;
};

/** The events the agent endpoints stream. Anything else in the stream is ignored. */
type StreamEvent =
    | { type: 'conversation'; id: string }
    | { type: 'text'; text: string }
    | { type: 'tool'; name: string }
    | { type: 'approval'; id: string; arguments: Record<string, string> }
    | { type: 'error'; message: string };

/** Keeps only string values, so nothing unexpected from the model reaches the approval card. */
function stringRecord(value: unknown): Record<string, string> | null {
    if (typeof value !== 'object' || value === null) return null;

    return Object.fromEntries(Object.entries(value).filter((entry): entry is [string, string] => typeof entry[1] === 'string'));
}

/** Turns one SSE payload into a known event, or null. JSON.parse gives `unknown`, so check every field. */
function parseEvent(payload: string): StreamEvent | null {
    let value: unknown;

    try {
        value = JSON.parse(payload);
    } catch {
        return null;
    }

    if (typeof value !== 'object' || value === null || !('type' in value)) {
        return null;
    }

    const event = value as Record<string, unknown>;

    if (event.type === 'conversation' && typeof event.id === 'string') return { type: 'conversation', id: event.id };
    if (event.type === 'text' && typeof event.text === 'string') return { type: 'text', text: event.text };
    if (event.type === 'tool' && typeof event.name === 'string') return { type: 'tool', name: event.name };
    if (event.type === 'error' && typeof event.message === 'string') return { type: 'error', message: event.message };

    if (event.type === 'approval' && typeof event.id === 'string') {
        const args = stringRecord(event.arguments);
        if (args !== null) return { type: 'approval', id: event.id, arguments: args };
    }

    return null;
}

/** Laravel's XSRF-TOKEN cookie, decoded, for the X-XSRF-TOKEN header. */
function xsrfToken(): string {
    const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

/** The first validation message in a JSON 422 body, if there is one. */
async function firstValidationError(response: Response): Promise<string | null> {
    try {
        const body: unknown = await response.json();
        const errors = typeof body === 'object' && body !== null && 'errors' in body ? (body as { errors: unknown }).errors : null;
        const first = typeof errors === 'object' && errors !== null ? Object.values(errors)[0] : null;

        return Array.isArray(first) && typeof first[0] === 'string' ? first[0] : null;
    } catch {
        return null;
    }
}

const STATUS_MESSAGES: Record<number, string> = {
    403: 'You do not have access to do that in this portfolio.',
    404: 'That conversation could not be found. Start a new conversation.',
    429: 'Too many requests, wait a moment.',
    503: 'The assistant is not configured.',
};

const TOOL_LABELS: Record<string, string> = {
    get_portfolio_summary: 'portfolio summary',
    get_allocation: 'allocation',
    list_transactions: 'transactions',
    log_transaction: 'log transaction',
};

export default function AgentPanel({
    portfolioId,
    portfolioName,
    configured,
    canWrite,
}: {
    portfolioId: number;
    portfolioName: string;
    configured: boolean;
    canWrite: boolean;
}) {
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [input, setInput] = useState('');
    const [streaming, setStreaming] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [available, setAvailable] = useState(configured);
    const [pending, setPending] = useState<PendingApproval | null>(null);
    const conversationId = useRef<string | null>(null);
    const abort = useRef<AbortController | null>(null);
    const list = useRef<HTMLDivElement>(null);
    const stickToBottom = useRef(true);

    const starters = canWrite
        ? ['Summarize my portfolio', 'How is my allocation?', 'Log a buy of 0.1 BTC at 65000']
        : ['Summarize my portfolio', 'How is my allocation?'];

    // Auto-scroll only if the reader was already near the bottom, so scrolling up to read is not undone.
    useEffect(() => {
        if (stickToBottom.current && list.current) {
            list.current.scrollTop = list.current.scrollHeight;
        }
    }, [messages, pending]);

    function onScroll() {
        const element = list.current;

        if (element) {
            stickToBottom.current = element.scrollHeight - element.scrollTop - element.clientHeight < 40;
        }
    }

    /** Applies a change to the assistant message being streamed (always the last one). */
    function updateReply(change: (reply: ChatMessage) => ChatMessage) {
        setMessages((current) => current.map((message, index) => (index === current.length - 1 ? change(message) : message)));
    }

    function handleEvent(event: StreamEvent) {
        if (event.type === 'conversation') conversationId.current = event.id;
        else if (event.type === 'text') updateReply((reply) => ({ ...reply, content: reply.content + event.text }));
        else if (event.type === 'tool') updateReply((reply) => ({ ...reply, tools: [...reply.tools, event.name] }));
        else if (event.type === 'approval') setPending({ id: event.id, arguments: event.arguments });
        else setError(event.message);
    }

    /**
     * POSTs to one of the agent endpoints and reads its event stream into the last assistant message.
     * Shared by chat messages and approval decisions, which stream in exactly the same format.
     */
    async function streamFrom(url: string, body: Record<string, string | null>) {
        setError(null);
        setStreaming(true);
        stickToBottom.current = true;
        abort.current = new AbortController();

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                signal: abort.current.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify(body),
            });

            if (!response.ok || response.body === null) {
                if (response.status === 503) setAvailable(false);
                if (response.status === 404) conversationId.current = null;
                const validation = response.status === 422 ? await firstValidationError(response) : null;
                setError(validation ?? STATUS_MESSAGES[response.status] ?? 'Something went wrong. Please try again.');
                setMessages((current) => current.slice(0, -1));
                return;
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            for (;;) {
                const { done, value } = await reader.read();

                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const blocks = buffer.split('\n\n');
                buffer = blocks.pop() ?? '';

                for (const block of blocks) {
                    const payload = block.replace(/^data: /, '');

                    if (payload === '[DONE]') return;

                    const event = parseEvent(payload);
                    if (event !== null) handleEvent(event);
                }
            }
        } catch (caught) {
            if (!(caught instanceof DOMException && caught.name === 'AbortError')) {
                setError('Could not reach the assistant. Check your connection and try again.');
            }
        } finally {
            // Always leave the loading state, and refresh the numbers in case a transaction was logged.
            setStreaming(false);
            abort.current = null;
            router.reload({ only: ['summary', 'transactions'] });
        }
    }

    function send(text: string) {
        const message = text.trim();

        if (message === '' || streaming || !available || pending !== null) return;

        setMessages((current) => [...current, { role: 'user', content: message, tools: [] }, { role: 'assistant', content: '', tools: [] }]);
        setInput('');
        // Only the message and the server-issued id: the server keeps the history.
        void streamFrom(route('agent', portfolioId), { message, conversation_id: conversationId.current });
    }

    function decide(decision: 'approve' | 'reject') {
        if (pending === null || conversationId.current === null || streaming) return;

        const toolCallId = pending.id;
        setPending(null);
        setMessages((current) => [...current, { role: 'assistant', content: '', tools: [] }]);
        void streamFrom(route('agent.approvals', portfolioId), {
            conversation_id: conversationId.current,
            tool_call_id: toolCallId,
            decision,
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        send(input);
    }

    function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
        // Enter sends; Shift+Enter makes a new line.
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            send(input);
        }
    }

    function newConversation() {
        abort.current?.abort();
        conversationId.current = null;
        setMessages([]);
        setPending(null);
        setError(null);
    }

    return (
        <section aria-labelledby="assistant-heading" className="flex flex-col rounded-lg border border-gray-200 bg-white">
            <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                <h3 id="assistant-heading" className="text-sm font-medium text-gray-900">
                    Assistant
                </h3>
                <button
                    type="button"
                    onClick={newConversation}
                    disabled={messages.length === 0}
                    className="rounded text-xs text-gray-600 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40"
                >
                    New conversation
                </button>
            </div>

            <div ref={list} onScroll={onScroll} aria-live="polite" className="max-h-96 min-h-32 space-y-3 overflow-y-auto px-4 py-3 text-sm">
                {!available && <p className="text-gray-600">The assistant is not configured.</p>}

                {available && messages.length === 0 && (
                    <div className="space-y-2">
                        <p className="text-gray-600">Ask about this portfolio. Try:</p>
                        <div className="flex flex-wrap gap-2">
                            {starters.map((starter) => (
                                <button
                                    key={starter}
                                    type="button"
                                    onClick={() => send(starter)}
                                    className="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-700 hover:border-gray-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                                >
                                    {starter}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                {messages.map((message, index) => (
                    <div key={index} className={message.role === 'user' ? 'text-right' : ''}>
                        {message.tools.length > 0 && (
                            <div className="mb-1 flex flex-wrap gap-1">
                                {message.tools.map((tool, toolIndex) => (
                                    <span key={toolIndex} className="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">
                                        Using: {TOOL_LABELS[tool] ?? tool}
                                    </span>
                                ))}
                            </div>
                        )}
                        {/* Plain text, never HTML: nothing from the model or a tool result can inject markup. */}
                        {(message.content !== '' || message.role === 'user' || streaming) && (
                            <p
                                className={`inline-block max-w-full whitespace-pre-wrap rounded-lg px-3 py-2 text-left ${
                                    message.role === 'user' ? 'bg-gray-800 text-white' : 'bg-gray-50 text-gray-900'
                                }`}
                            >
                                {message.content === '' && message.role === 'assistant' ? 'Thinking…' : message.content}
                            </p>
                        )}
                    </div>
                ))}

                {pending !== null && (
                    <div role="group" aria-labelledby="approval-heading" className="rounded-lg border border-indigo-200 bg-indigo-50 p-3">
                        <p id="approval-heading" className="text-xs font-medium text-indigo-900">
                            The assistant wants to log this transaction. Nothing is saved until you approve.
                        </p>
                        {/* Exactly what will happen, from the arguments the model sent. */}
                        <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs tabular-nums text-gray-800">
                            <dt className="text-gray-500">Portfolio</dt>
                            <dd>{portfolioName}</dd>
                            <dt className="text-gray-500">Asset</dt>
                            <dd>{pending.arguments.asset_symbol ?? '?'}</dd>
                            <dt className="text-gray-500">Type</dt>
                            <dd className="capitalize">{pending.arguments.type ?? '?'}</dd>
                            <dt className="text-gray-500">Quantity</dt>
                            <dd>{pending.arguments.quantity ?? '?'}</dd>
                            <dt className="text-gray-500">Unit price</dt>
                            <dd>{pending.arguments.price ?? '?'}</dd>
                            {pending.arguments.note !== undefined && (
                                <>
                                    <dt className="text-gray-500">Note</dt>
                                    <dd className="break-words">{pending.arguments.note}</dd>
                                </>
                            )}
                        </dl>
                        <div className="mt-3 flex gap-2">
                            <button
                                type="button"
                                onClick={() => decide('approve')}
                                className="rounded-md bg-gray-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                            >
                                Approve
                            </button>
                            <button
                                type="button"
                                onClick={() => decide('reject')}
                                className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                            >
                                Reject
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {error !== null && (
                <p role="alert" className="mx-4 mb-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                    {error}
                </p>
            )}

            <form onSubmit={submit} className="border-t border-gray-100 p-3">
                <label htmlFor="assistant-input" className="sr-only">
                    Message the assistant
                </label>
                <textarea
                    id="assistant-input"
                    value={input}
                    onChange={(event) => setInput(event.target.value)}
                    onKeyDown={onKeyDown}
                    disabled={streaming || !available || pending !== null}
                    rows={2}
                    maxLength={4000}
                    placeholder={!available ? 'Not available' : pending !== null ? 'Approve or reject the transaction first' : 'Ask a question…'}
                    className="block w-full resize-none rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-50"
                />
                <div className="mt-2 flex items-center justify-between gap-2">
                    <p className="text-xs text-gray-500">The assistant can only do what your role allows.</p>
                    {streaming ? (
                        <button
                            type="button"
                            onClick={() => abort.current?.abort()}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                        >
                            Stop
                        </button>
                    ) : (
                        <button
                            type="submit"
                            disabled={!available || pending !== null || input.trim() === ''}
                            className="rounded-md bg-gray-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40"
                        >
                            Send
                        </button>
                    )}
                </div>
            </form>
        </section>
    );
}
