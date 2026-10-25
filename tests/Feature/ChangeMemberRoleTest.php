<?php

declare(strict_types=1);

use App\Actions\ChangeMemberRole;
use App\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('owners can change a member\'s role', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $editor = memberWithRole($portfolio, Role::Editor);

    $member = app(ChangeMemberRole::class)->execute($owner, $portfolio, $editor, Role::Viewer);

    expect($member->role)->toBe(Role::Viewer);
    $this->assertDatabaseHas('portfolio_members', ['id' => $editor->id, 'role' => 'viewer']);
});

test('editors and viewers cannot change roles', function (Role $role) {
    [$user, $portfolio] = portfolioWithRole($role);
    $viewer = memberWithRole($portfolio, Role::Viewer);

    expect(fn () => app(ChangeMemberRole::class)->execute($user, $portfolio, $viewer, Role::Owner))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('portfolio_members', ['id' => $viewer->id, 'role' => 'viewer']);
})->with([Role::Editor, Role::Viewer]);

test('the last owner cannot be demoted', function (Role $newRole) {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $ownMembership = $portfolio->members()->where('user_id', $owner->id)->firstOrFail();

    expect(fn () => app(ChangeMemberRole::class)->execute($owner, $portfolio, $ownMembership, $newRole))
        ->toThrow(ValidationException::class, 'A portfolio needs at least one owner.');

    $this->assertDatabaseHas('portfolio_members', ['id' => $ownMembership->id, 'role' => 'owner']);
})->with([Role::Editor, Role::Viewer]);

test('an owner can be demoted once another owner exists', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $ownMembership = $portfolio->members()->where('user_id', $owner->id)->firstOrFail();
    memberWithRole($portfolio, Role::Owner);

    app(ChangeMemberRole::class)->execute($owner, $portfolio, $ownMembership, Role::Editor);

    $this->assertDatabaseHas('portfolio_members', ['id' => $ownMembership->id, 'role' => 'editor']);
});

test('the check uses the stored role, not the role on a stale model', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $ownMembership = $portfolio->members()->where('user_id', $owner->id)->firstOrFail();
    $staleCopy = $ownMembership->replicate()->forceFill(['id' => $ownMembership->id, 'role' => Role::Viewer]);
    $staleCopy->exists = true;

    expect(fn () => app(ChangeMemberRole::class)->execute($owner, $portfolio, $staleCopy, Role::Editor))
        ->toThrow(ValidationException::class, 'A portfolio needs at least one owner.');
});

test('a member of another portfolio cannot be targeted', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    [, $otherPortfolio] = portfolioWithRole(Role::Owner);
    $foreignViewer = memberWithRole($otherPortfolio, Role::Viewer);

    expect(fn () => app(ChangeMemberRole::class)->execute($owner, $portfolio, $foreignViewer, Role::Editor))
        ->toThrow(ValidationException::class, 'Member not found.');

    $this->assertDatabaseHas('portfolio_members', ['id' => $foreignViewer->id, 'role' => 'viewer']);
});
