<?php

use App\Enums\Role;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\User;

test('portfolioWithRole helper works', function () {
    [$user, $portfolio] = portfolioWithRole(Role::Viewer);

    expect($user)->toBeInstanceOf(User::class);
    expect($portfolio)->toBeInstanceOf(Portfolio::class);
    expect($portfolio->roleFor($user))->toBe(Role::Viewer);
});

test('globalAsset helper works', function () {
    $asset = globalAsset('BTC');

    expect($asset)->toBeInstanceOf(Asset::class);
    expect($asset->symbol)->toBe('BTC');
    expect($asset->isGlobal())->toBeTrue();
});
