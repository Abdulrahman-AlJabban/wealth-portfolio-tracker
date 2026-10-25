<?php

declare(strict_types=1);

namespace App\Agent;

use App\Enums\Role;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who the assistant is acting for, built on the server from the session and the route.
 * The model never supplies a user or a portfolio; every tool reads them from here.
 */
final readonly class AgentContext
{
    public function __construct(
        public User $user,
        public Portfolio $portfolio,
        public string $runId,
    ) {}

    /**
     * The user's current role, read fresh each time so a role change mid-run takes effect.
     *
     * @throws AuthorizationException when the user is not a member of the portfolio
     */
    public function role(): Role
    {
        return $this->portfolio->roleFor($this->user)
            ?? throw new AuthorizationException('You are not a member of this portfolio.');
    }
}
