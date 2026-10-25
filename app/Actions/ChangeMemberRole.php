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
 * Changes a member's role. Owner only, and never leaves the portfolio without an Owner.
 */
final class ChangeMemberRole
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, Portfolio $portfolio, PortfolioMember $member, Role $newRole): PortfolioMember
    {
        Gate::forUser($actor)->authorize('manage', $portfolio);

        if ($member->portfolio_id !== $portfolio->id) {
            throw ValidationException::withMessages(['member' => 'Member not found.']);
        }

        return DB::transaction(function () use ($portfolio, $member, $newRole) {
            // Locking the owner rows makes two owners demoting each other at the same time take turns.
            // The member's current role is read from these locked rows, not from the possibly stale $member.
            $ownerIds = $portfolio->members()
                ->where('role', Role::Owner->value)
                ->lockForUpdate()
                ->pluck('id');

            $isOwner = $ownerIds->contains($member->id);

            if ($isOwner && $newRole !== Role::Owner && $ownerIds->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'A portfolio needs at least one owner.']);
            }

            $member->update(['role' => $newRole]);

            return $member;
        });
    }
}
