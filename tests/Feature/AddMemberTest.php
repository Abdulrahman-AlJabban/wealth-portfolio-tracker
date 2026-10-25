<?php

declare(strict_types=1);

use App\Actions\AddMember;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('owners can add a user with any role', function (Role $role) {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $newcomer = User::factory()->create();

    $member = app(AddMember::class)->execute($owner, $portfolio, $newcomer->id, $role);

    $this->assertDatabaseHas('portfolio_members', [
        'id' => $member->id,
        'portfolio_id' => $portfolio->id,
        'user_id' => $newcomer->id,
        'role' => $role->value,
    ]);
    expect($portfolio->roleFor($newcomer))->toBe($role);
})->with([Role::Viewer, Role::Editor, Role::Owner]);

test('editors and viewers cannot add members', function (Role $role) {
    [$user, $portfolio] = portfolioWithRole($role);
    $newcomer = User::factory()->create();

    expect(fn () => app(AddMember::class)->execute($user, $portfolio, $newcomer->id, Role::Viewer))
        ->toThrow(AuthorizationException::class);

    expect($portfolio->roleFor($newcomer))->toBeNull();
})->with([Role::Editor, Role::Viewer]);

test('a user who does not exist is rejected', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);

    expect(fn () => app(AddMember::class)->execute($owner, $portfolio, 999999, Role::Viewer))
        ->toThrow(ValidationException::class, 'User not found.');
});

test('an existing member cannot be added again', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $viewer = memberWithRole($portfolio, Role::Viewer);

    expect(fn () => app(AddMember::class)->execute($owner, $portfolio, $viewer->user_id, Role::Editor))
        ->toThrow(ValidationException::class, 'This user is already a member of the portfolio.');

    $this->assertDatabaseHas('portfolio_members', ['id' => $viewer->id, 'role' => 'viewer']);
    $this->assertDatabaseCount('portfolio_members', 2);
});
