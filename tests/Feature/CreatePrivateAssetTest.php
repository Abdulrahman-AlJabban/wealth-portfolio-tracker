<?php

declare(strict_types=1);

use App\Actions\CreatePrivateAsset;
use App\Enums\AssetType;
use App\Enums\PriceSource;
use App\Enums\Role;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('editors and owners can add a private asset', function (Role $role, AssetType $type) {
    [$user, $portfolio] = portfolioWithRole($role);

    $asset = app(CreatePrivateAsset::class)->execute($user, $portfolio, '  Rental flat  ', $type);

    $this->assertDatabaseHas('assets', [
        'id' => $asset->id,
        'portfolio_id' => $portfolio->id,
        'type' => $type->value,
        'symbol' => null,
        'external_id' => null,
        'name' => 'Rental flat',
        'currency' => 'USD',
        'price_source' => 'manual',
    ]);
})->with([Role::Editor, Role::Owner])->with([AssetType::RealEstate, AssetType::Cash]);

test('the asset uses the portfolio\'s base currency', function () {
    $owner = User::factory()->create();
    $portfolio = Portfolio::create(['name' => 'Euro savings', 'base_currency' => 'EUR', 'created_by' => $owner->id]);
    $portfolio->members()->create(['user_id' => $owner->id, 'role' => Role::Owner->value]);

    $asset = app(CreatePrivateAsset::class)->execute($owner, $portfolio, 'Bank account', AssetType::Cash);

    expect($asset->currency)->toBe('EUR');
});

test('the created asset is always private and manually priced', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);

    $asset = app(CreatePrivateAsset::class)->execute($owner, $portfolio, 'Flat', AssetType::RealEstate);

    expect($asset->isGlobal())->toBeFalse()
        ->and($asset->price_source)->toBe(PriceSource::Manual);
});

test('viewers cannot add assets', function () {
    [$viewer, $portfolio] = portfolioWithRole(Role::Viewer);

    expect(fn () => app(CreatePrivateAsset::class)->execute($viewer, $portfolio, 'Flat', AssetType::RealEstate))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('assets', 0);
});

test('market asset types are rejected', function (AssetType $type) {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);

    expect(fn () => app(CreatePrivateAsset::class)->execute($owner, $portfolio, 'Bitcoin', $type))
        ->toThrow(ValidationException::class, 'Only real estate and cash can be added as private assets.');

    $this->assertDatabaseCount('assets', 0);
})->with([AssetType::Stock, AssetType::Crypto]);

test('invalid names are rejected', function (string $name) {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);

    expect(fn () => app(CreatePrivateAsset::class)->execute($owner, $portfolio, $name, AssetType::Cash))
        ->toThrow(ValidationException::class, 'The name must be between 1 and 100 characters.');

    $this->assertDatabaseCount('assets', 0);
})->with([
    'empty' => '',
    'only spaces' => '   ',
    '101 characters' => str_repeat('a', 101),
]);
