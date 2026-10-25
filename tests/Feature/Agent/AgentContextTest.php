<?php

declare(strict_types=1);

use App\Agent\AgentContext;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

test('returns the member\'s role', function (Role $role) {
    [$user, $portfolio] = portfolioWithRole($role);

    expect((new AgentContext($user, $portfolio, 'run-1'))->role())->toBe($role);
})->with([Role::Owner, Role::Editor, Role::Viewer]);

test('throws for a non-member', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $context = new AgentContext(User::factory()->create(), $portfolio, 'run-1');

    expect(fn () => $context->role())->toThrow(AuthorizationException::class, 'You are not a member of this portfolio.');
});

test('reads the role fresh, so a demotion during a run takes effect', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $context = new AgentContext($editor, $portfolio, 'run-1');
    expect($context->role())->toBe(Role::Editor);

    $portfolio->members()->where('user_id', $editor->id)->update(['role' => Role::Viewer->value]);

    expect($context->role())->toBe(Role::Viewer);
});
