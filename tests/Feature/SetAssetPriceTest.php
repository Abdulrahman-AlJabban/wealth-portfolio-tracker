<?php

declare(strict_types=1);

use App\Actions\SetAssetPrice;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Portfolio;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

function privateAsset(Portfolio $portfolio, string $currency = 'USD', string $priceSource = 'manual'): Asset
{
    return Asset::create([
        'portfolio_id' => $portfolio->id,
        'type' => 'real_estate',
        'name' => 'Damascus flat',
        'currency' => $currency,
        'price_source' => $priceSource,
    ]);
}

beforeEach(function () {
    $this->travelTo('2026-10-25 12:00:00');
});

test('editors and owners can value their portfolio\'s private asset', function (Role $role) {
    [$user, $portfolio] = portfolioWithRole($role);
    $flat = privateAsset($portfolio);

    $snapshot = app(SetAssetPrice::class)->execute($user, $portfolio, $flat, '85000.50');

    $this->assertDatabaseHas('price_snapshots', [
        'id' => $snapshot->id,
        'asset_id' => $flat->id,
        'price' => '85000.50000000',
        'currency' => 'USD',
        'as_of' => '2026-10-25 12:00:00',
        'source' => 'manual',
        'created_by' => $user->id,
    ]);
    expect($flat->latestSnapshot->price)->toBe('85000.50000000');
})->with([Role::Editor, Role::Owner]);

test('the snapshot uses the asset\'s currency and the given date', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $flat = privateAsset($portfolio, 'EUR');

    app(SetAssetPrice::class)->execute($editor, $portfolio, $flat, '70000', CarbonImmutable::parse('2026-09-30 00:00:00'));

    $this->assertDatabaseHas('price_snapshots', [
        'asset_id' => $flat->id,
        'currency' => 'EUR',
        'as_of' => '2026-09-30 00:00:00',
    ]);
});

test('a second valuation for the same moment replaces the first', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $flat = privateAsset($portfolio);
    $setPrice = app(SetAssetPrice::class);

    $setPrice->execute($editor, $portfolio, $flat, '80000');
    $setPrice->execute($editor, $portfolio, $flat, '81000');

    $this->assertDatabaseCount('price_snapshots', 1);
    $this->assertDatabaseHas('price_snapshots', ['asset_id' => $flat->id, 'price' => '81000.00000000']);
});

test('nobody can price a global market asset (R1)', function (Role $role) {
    [$user, $portfolio] = portfolioWithRole($role);
    $btc = globalAsset();

    expect(fn () => app(SetAssetPrice::class)->execute($user, $portfolio, $btc, '1'))
        ->toThrow(AuthorizationException::class, 'This asset cannot be priced manually.');

    $this->assertDatabaseCount('price_snapshots', 0);
})->with([Role::Owner, Role::Editor]);

test('a private asset marked as market priced cannot be priced manually', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $asset = privateAsset($portfolio, priceSource: 'market');

    expect(fn () => app(SetAssetPrice::class)->execute($owner, $portfolio, $asset, '1'))
        ->toThrow(AuthorizationException::class, 'This asset cannot be priced manually.');
});

test('viewers cannot value a private asset (R2)', function () {
    [$viewer, $portfolio] = portfolioWithRole(Role::Viewer);
    $flat = privateAsset($portfolio);

    expect(fn () => app(SetAssetPrice::class)->execute($viewer, $portfolio, $flat, '85000'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('price_snapshots', 0);
});

test('an editor of another portfolio cannot value this portfolio\'s asset (R2)', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    [$otherEditor, $otherPortfolio] = portfolioWithRole(Role::Editor);
    $flat = privateAsset($portfolio);
    $setPrice = app(SetAssetPrice::class);

    expect(fn () => $setPrice->execute($otherEditor, $otherPortfolio, $flat, '1'))
        ->toThrow(AuthorizationException::class, 'This asset cannot be priced manually.')
        ->and(fn () => $setPrice->execute($otherEditor, $portfolio, $flat, '1'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('price_snapshots', 0);
});

test('invalid prices are rejected', function (string $price) {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $flat = privateAsset($portfolio);

    expect(fn () => app(SetAssetPrice::class)->execute($editor, $portfolio, $flat, $price))
        ->toThrow(ValidationException::class, 'Price must be a positive decimal with at most 8 decimal places.');

    $this->assertDatabaseCount('price_snapshots', 0);
})->with(['0', '-1', 'abc', '1e3', '1.123456789', '']);

test('a future valuation date is rejected', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $flat = privateAsset($portfolio);

    expect(fn () => app(SetAssetPrice::class)->execute($editor, $portfolio, $flat, '85000', CarbonImmutable::parse('2026-10-26 00:00:00')))
        ->toThrow(ValidationException::class, 'The valuation date cannot be in the future.');

    $this->assertDatabaseCount('price_snapshots', 0);
});
