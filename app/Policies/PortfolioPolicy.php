<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Portfolio;
use App\Models\User;

/**
 * Who may do what inside a portfolio. Roles inherit upward: Owner > Editor > Viewer.
 * Non-members have no role, so every check returns false for them.
 */
class PortfolioPolicy
{
    /**
     * Any member may see the dashboard, holdings and transactions.
     */
    public function view(User $user, Portfolio $portfolio): bool
    {
        return $portfolio->roleFor($user) !== null;
    }

    /**
     * Editors and Owners may log or reverse transactions and value private assets.
     */
    public function write(User $user, Portfolio $portfolio): bool
    {
        return $portfolio->roleFor($user)?->atLeast(Role::Editor) ?? false;
    }

    /**
     * Only Owners may manage members and delete the portfolio.
     */
    public function manage(User $user, Portfolio $portfolio): bool
    {
        return $portfolio->roleFor($user) === Role::Owner;
    }
}
