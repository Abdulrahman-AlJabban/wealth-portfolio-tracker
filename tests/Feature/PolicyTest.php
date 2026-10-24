<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * The permission matrix from BUILD_GUIDE.md section 1. Checks go through the Gate,
 * so these tests also prove that PortfolioPolicy is auto-discovered for Portfolio.
 */

test('members get the abilities of their role', function (Role $role, bool $view, bool $write, bool $manage) {
    [$user, $portfolio] = portfolioWithRole($role);
    $gate = Gate::forUser($user);

    expect($gate->allows('view', $portfolio))->toBe($view)
        ->and($gate->allows('write', $portfolio))->toBe($write)
        ->and($gate->allows('manage', $portfolio))->toBe($manage);
})->with([
    'owner' => [Role::Owner, true, true, true],
    'editor' => [Role::Editor, true, true, false],
    'viewer' => [Role::Viewer, true, false, false],
]);

test('non-members get no abilities', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $gate = Gate::forUser(User::factory()->create());

    expect($gate->allows('view', $portfolio))->toBeFalse()
        ->and($gate->allows('write', $portfolio))->toBeFalse()
        ->and($gate->allows('manage', $portfolio))->toBeFalse();
});
