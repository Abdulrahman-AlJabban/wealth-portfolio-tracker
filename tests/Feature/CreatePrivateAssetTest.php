<?php

declare(strict_types=1);

use App\Actions\CreatePrivateAsset;
use App\Enums\AssetType;
use App\Enums\PriceSource;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
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

test('a duplicate name in the same portfolio is rejected, ignoring case', function (string $duplicate) {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $createAsset = app(CreatePrivateAsset::class);
    $createAsset->execute($owner, $portfolio, 'Rental flat', AssetType::RealEstate);

    expect(fn () => $createAsset->execute($owner, $portfolio, $duplicate, AssetType::Cash))
        ->toThrow(ValidationException::class, 'This portfolio already has an asset with that name.');

    $this->assertDatabaseCount('assets', 1);
})->with(['Rental flat', 'rental FLAT', '  Rental flat  ']);

test('the same name is allowed in another portfolio', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    [$otherOwner, $otherPortfolio] = portfolioWithRole(Role::Owner);
    $createAsset = app(CreatePrivateAsset::class);
    $createAsset->execute($owner, $portfolio, 'Rental flat', AssetType::RealEstate);

    $createAsset->execute($otherOwner, $otherPortfolio, 'Rental flat', AssetType::RealEstate);

    $this->assertDatabaseCount('assets', 2);
});

test('a private name may match a global asset\'s name', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    globalAsset('BTC');

    $asset = app(CreatePrivateAsset::class)->execute($owner, $portfolio, 'BTC', AssetType::Cash);

    expect($asset->portfolio_id)->toBe($portfolio->id);
});

test('the database itself refuses a duplicate private name', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $row = ['portfolio_id' => $portfolio->id, 'type' => 'cash', 'name' => 'Savings', 'currency' => 'USD', 'price_source' => 'manual'];
    Asset::create($row);

    expect(fn () => Asset::create($row))->toThrow(QueryException::class);
});

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
