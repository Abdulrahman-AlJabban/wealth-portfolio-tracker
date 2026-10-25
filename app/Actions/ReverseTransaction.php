<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionSource;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PositionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a ledger row without editing history (R5): it appends an opposite row that points
 * back at the original through reverses_id. The unique index on reverses_id means the database
 * itself refuses a second reversal of the same row.
 */
final class ReverseTransaction
{
    public function __construct(private PositionService $positions) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $user, Portfolio $portfolio, Transaction $original): Transaction
    {
        Gate::forUser($user)->authorize('write', $portfolio);

        return DB::transaction(function () use ($user, $portfolio, $original) {
            // Serializes writers on this portfolio, so the checks below cannot race another reversal or sell.
            Portfolio::query()->whereKey($portfolio->id)->lockForUpdate()->firstOrFail();

            // "Not found" rather than "forbidden", so this cannot confirm another portfolio's row exists.
            if ($original->portfolio_id !== $portfolio->id) {
                throw ValidationException::withMessages(['transaction' => 'Transaction not found.']);
            }

            if ($original->reverses_id !== null) {
                throw ValidationException::withMessages(['transaction' => 'A reversal cannot be reversed.']);
            }

            if (Transaction::query()->where('reverses_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['transaction' => 'This transaction has already been reversed.']);
            }

            $type = $original->type->opposite();

            $reversal = Transaction::create([
                'portfolio_id' => $portfolio->id,
                'asset_id' => $original->asset_id,
                'type' => $type,
                'quantity' => $original->quantity,
                'price' => $original->price,
                'occurred_at' => now(),
                'created_by' => $user->id,
                'source' => TransactionSource::User,
                'reverses_id' => $original->id,
                'note' => "Reversal of #{$original->id}",
            ]);

            // R4: reversing a buy is a sell. If the bought quantity was already sold, the position
            // would go negative, so the insert is rolled back.
            if ($type->isOutflow()) {
                $this->positions->assertNeverNegative($portfolio->id, $original->asset_id);
            }

            return $reversal;
        });
    }
}
