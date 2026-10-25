<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Soft-deletes a portfolio. Owner only. Nothing is destroyed, so the append-only ledger (R5)
 * survives, and route binding hides the portfolio from then on.
 */
final class DeletePortfolio
{
    /**
     * @throws AuthorizationException
     */
    public function execute(User $user, Portfolio $portfolio): void
    {
        Gate::forUser($user)->authorize('manage', $portfolio);

        DB::transaction(fn () => $portfolio->delete());
    }
}
