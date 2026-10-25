<?php

declare(strict_types=1);

use App\Actions\RemoveMember;
use App\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('owners can remove a member', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $editor = memberWithRole($portfolio, Role::Editor);

    app(RemoveMember::class)->execute($owner, $portfolio, $editor);

    $this->assertModelMissing($editor);
});

test('editors and viewers cannot remove members', function (Role $role) {
    [$user, $portfolio] = portfolioWithRole($role);
    $viewer = memberWithRole($portfolio, Role::Viewer);

    expect(fn () => app(RemoveMember::class)->execute($user, $portfolio, $viewer))
        ->toThrow(AuthorizationException::class);

    $this->assertModelExists($viewer);
})->with([Role::Editor, Role::Viewer]);

test('the last owner cannot be removed', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $ownMembership = $portfolio->members()->where('user_id', $owner->id)->firstOrFail();

    expect(fn () => app(RemoveMember::class)->execute($owner, $portfolio, $ownMembership))
        ->toThrow(ValidationException::class, 'A portfolio needs at least one owner.');

    $this->assertModelExists($ownMembership);
});

test('an owner can be removed once another owner exists', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $ownMembership = $portfolio->members()->where('user_id', $owner->id)->firstOrFail();
    memberWithRole($portfolio, Role::Owner);

    app(RemoveMember::class)->execute($owner, $portfolio, $ownMembership);

    $this->assertModelMissing($ownMembership);
});

test('a member of another portfolio cannot be targeted', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    [, $otherPortfolio] = portfolioWithRole(Role::Owner);
    $foreignViewer = memberWithRole($otherPortfolio, Role::Viewer);

    expect(fn () => app(RemoveMember::class)->execute($owner, $portfolio, $foreignViewer))
        ->toThrow(ValidationException::class, 'Member not found.');

    $this->assertModelExists($foreignViewer);
});
