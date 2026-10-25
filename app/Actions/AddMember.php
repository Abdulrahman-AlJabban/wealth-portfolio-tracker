<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Role;
use App\Models\Portfolio;
use App\Models\PortfolioMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Shares a portfolio with another user. Owner only.
 */
final class AddMember
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, Portfolio $portfolio, int $userId, Role $role): PortfolioMember
    {
        Gate::forUser($actor)->authorize('manage', $portfolio);

        return DB::transaction(function () use ($portfolio, $userId, $role) {
            // Serializes member changes on this portfolio, so two adds of the same user cannot
            // both pass the check below and the second fail on the unique index.
            Portfolio::query()->whereKey($portfolio->id)->lockForUpdate()->firstOrFail();

            if (! User::query()->whereKey($userId)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'User not found.']);
            }

            if ($portfolio->members()->where('user_id', $userId)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'This user is already a member of the portfolio.']);
            }

            return $portfolio->members()->create([
                'user_id' => $userId,
                'role' => $role,
            ]);
        });
    }
}
