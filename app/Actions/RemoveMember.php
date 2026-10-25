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
 * Removes a member from a portfolio. Owner only, and never removes the last Owner.
 */
final class RemoveMember
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, Portfolio $portfolio, PortfolioMember $member): void
    {
        Gate::forUser($actor)->authorize('manage', $portfolio);

        if ($member->portfolio_id !== $portfolio->id) {
            throw ValidationException::withMessages(['member' => 'Member not found.']);
        }

        DB::transaction(function () use ($portfolio, $member) {
            // Same guard as ChangeMemberRole: decide from the locked owner rows, not from $member.
            $ownerIds = $portfolio->members()
                ->where('role', Role::Owner->value)
                ->lockForUpdate()
                ->pluck('id');

            if ($ownerIds->contains($member->id) && $ownerIds->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'A portfolio needs at least one owner.']);
            }

            $member->delete();
        });
    }
}
