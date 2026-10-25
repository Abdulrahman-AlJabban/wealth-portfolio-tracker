<?php

declare(strict_types=1);

namespace App\Agent;

use App\Actions\LogTransaction;
use App\Models\AgentRun;
use App\Models\Transaction;
use App\Services\ValuationService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The single place where an assistant tool runs. The SDK tool classes are one-line adapters
 * that call execute(), so every tool call is authorized, audited and turned into JSON here.
 *
 * The model can only ask for a tool. User and portfolio come from AgentContext, never from
 * the model, and writes go through the same Actions the web form uses.
 */
final class AssistantTools
{
    /** Tools any member may be offered. */
    private const READ_TOOLS = ['get_portfolio_summary', 'get_allocation', 'list_transactions'];

    public function __construct(
        private AgentContext $context,
        private ValuationService $valuation,
        private LogTransaction $logTransaction,
    ) {}

    /**
     * Runs one tool and returns {"ok":true,"data":...} or {"ok":false,"error":"..."}. Never throws.
     *
     * @param  array<string, mixed>  $args  exactly as the model sent them
     */
    public function execute(string $tool, array $args): string
    {
        return $this->guarded($tool, $args, fn (): mixed => match ($tool) {
            'get_portfolio_summary' => $this->portfolioSummary(),
            'get_allocation' => $this->allocation(),
            'list_transactions' => $this->listTransactions($args),
            default => throw ValidationException::withMessages(['tool' => 'Unknown tool.']),
        });
    }

    /**
     * The tool names this context may be offered. Filtering by role keeps the model from trying
     * things it cannot do; it is not the security boundary. The Actions and policy are.
     *
     * @return list<string>
     *
     * @throws AuthorizationException for a non-member
     */
    public function toolNamesForRole(): array
    {
        $this->context->role();

        return self::READ_TOOLS;
    }

    /**
     * Runs the tool body, maps domain errors to an outcome, writes one audit row, and returns JSON.
     *
     * @param  array<string, mixed>  $args
     */
    private function guarded(string $tool, array $args, Closure $run): string
    {
        $outcome = 'ok';
        $error = null;
        $data = null;

        try {
            $data = $run();
        } catch (AuthorizationException $e) {
            $outcome = 'denied';
            $error = $e->getMessage() ?: 'Not allowed for your role.';
        } catch (ValidationException $e) {
            $outcome = 'invalid';
            $error = collect($e->errors())->flatten()->first() ?? 'Invalid input.';
        } catch (ModelNotFoundException) {
            $outcome = 'invalid';
            $error = 'Not found.';
        } catch (Throwable $e) {
            report($e);
            $outcome = 'error';
            // Never pass internal or provider details to the model.
            $error = 'Something went wrong.';
        }

        $this->audit($tool, $args, $outcome, $error);

        $payload = $outcome === 'ok' ? ['ok' => true, 'data' => $data] : ['ok' => false, 'error' => $error];

        // Database strings (asset names, notes) only ever appear as JSON string values.
        return json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"ok":false,"error":"Something went wrong."}';
    }

    /**
     * One agent_runs row per tool call, including refused ones. A failure to audit is reported
     * but does not break the tool call.
     *
     * @param  array<string, mixed>  $args
     */
    private function audit(string $tool, array $args, string $outcome, ?string $error): void
    {
        try {
            AgentRun::create([
                'user_id' => $this->context->user->id,
                'portfolio_id' => $this->context->portfolio->id,
                'run_id' => $this->context->runId,
                // The name comes from the model; the column holds 64 characters.
                'tool' => mb_substr($tool, 0, 64),
                'input' => $args,
                'outcome' => $outcome,
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeView(): void
    {
        Gate::forUser($this->context->user)->authorize('view', $this->context->portfolio);
    }

    /**
     * @return array<string, mixed>
     */
    private function portfolioSummary(): array
    {
        $this->authorizeView();

        return $this->valuation->summary($this->context->portfolio);
    }

    /**
     * @return array<string, mixed>
     */
    private function allocation(): array
    {
        $this->authorizeView();

        return $this->valuation->allocation($this->context->portfolio);
    }

    /**
     * The latest transactions, newest first, with only the fields the model needs.
     *
     * @param  array<string, mixed>  $args  limit (1 to 20, default 10) and an optional symbol
     * @return list<array<string, mixed>>
     */
    private function listTransactions(array $args): array
    {
        $this->authorizeView();

        $query = Transaction::query()
            ->with('asset:id,symbol')
            ->where('portfolio_id', $this->context->portfolio->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($this->limit($args['limit'] ?? null));

        $symbol = $args['symbol'] ?? null;

        if ($symbol !== null && $symbol !== '') {
            // Only assets this portfolio may use, so another portfolio's private asset is never found.
            $asset = $this->context->portfolio->usableAssets()->where('symbol', strtoupper((string) $symbol))->first();

            if ($asset === null) {
                throw ValidationException::withMessages(['symbol' => 'No asset with that symbol in this portfolio.']);
            }

            $query->where('asset_id', $asset->id);
        }

        return $query->get()->map(fn (Transaction $transaction): array => [
            'id' => $transaction->id,
            'date' => $transaction->occurred_at->toIso8601String(),
            'type' => $transaction->type->value,
            'symbol' => $transaction->asset->symbol,
            'quantity' => $transaction->quantity,
            'price' => $transaction->price,
            'source' => $transaction->source->value,
        ])->all();
    }

    /**
     * The model is told limit is a string, but may send a number. Whole numbers are clamped to 1..20.
     */
    private function limit(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 10;
        }

        $limit = filter_var($value, FILTER_VALIDATE_INT);

        if ($limit === false) {
            throw ValidationException::withMessages(['limit' => 'The limit must be a whole number from 1 to 20.']);
        }

        return max(1, min(20, $limit));
    }
}
