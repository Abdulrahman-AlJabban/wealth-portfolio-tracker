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
 * Shares a portfolio with another user, found by email. Owner only, so only Owners can learn
 * whether an email address has an account.
 */
final class AddMember
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, Portfolio $portfolio, string $email, Role $role): PortfolioMember
    {
        Gate::forUser($actor)->authorize('manage', $portfolio);

        return DB::transaction(function () use ($portfolio, $email, $role) {
            // Serializes member changes on this portfolio, so two adds of the same user cannot
            // both pass the check below and the second fail on the unique index.
            Portfolio::query()->whereKey($portfolio->id)->lockForUpdate()->firstOrFail();

            // The email column's collation is case-insensitive, so "Bob@Example.com" finds bob@example.com.
            $user = User::query()->where('email', trim($email))->first();

            if ($user === null) {
                throw ValidationException::withMessages(['email' => 'No user with that email.']);
            }

            if ($portfolio->members()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This user is already a member of the portfolio.']);
            }

            return $portfolio->members()->create([
                'user_id' => $user->id,
                'role' => $role,
            ]);
        });
    }
}
