<?php

declare(strict_types=1);

use App\Actions\LogTransaction;
use App\Data\LogTransactionData;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\PriceSnapshot;
use App\Models\User;
use App\Services\ValuationService;

/**
 * Buys $quantity of $asset and, if $price is given, records it as the asset's latest price.
 */
function holdAt(User $owner, Portfolio $portfolio, Asset $asset, string $quantity, ?string $price): void
{
    app(LogTransaction::class)->execute($owner, $portfolio, new LogTransactionData($asset->id, TransactionType::Buy, $quantity, '1'));

    if ($price !== null) {
        PriceSnapshot::create(['asset_id' => $asset->id, 'price' => $price, 'currency' => 'USD', 'as_of' => now(), 'source' => 'market']);
    }
}

/**
 * The seeded "Family wealth" holdings: BTC 32,500 + ETH 12,800 + AAPL 2,300 + flat 85,000 = 132,600.
 */
function seededLikePortfolio(): Portfolio
{
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $aapl = Asset::create(['portfolio_id' => null, 'type' => 'stock', 'symbol' => 'AAPL', 'name' => 'Apple Inc.', 'currency' => 'USD', 'price_source' => 'market']);
    $flat = Asset::create(['portfolio_id' => $portfolio->id, 'type' => 'real_estate', 'name' => 'Damascus flat', 'currency' => 'USD', 'price_source' => 'manual']);

    holdAt($owner, $portfolio, globalAsset('BTC'), '0.5', '65000');
    holdAt($owner, $portfolio, globalAsset('ETH'), '4', '3200');
    holdAt($owner, $portfolio, $aapl, '10', '230');
    holdAt($owner, $portfolio, $flat, '1', '85000');

    return $portfolio;
}

test('weights each asset by its share of total value, largest first', function () {
    $allocation = app(ValuationService::class)->allocation(seededLikePortfolio());

    // 85000 / 132600 = 64.102%, 32500 / 132600 = 24.509%, 12800 / 132600 = 9.653%, 2300 / 132600 = 1.734%
    expect($allocation['total_value'])->toBe('132600.00000000')
        ->and(collect($allocation['by_asset'])->map(fn ($row) => [$row['name'], $row['weight_pct']])->all())->toBe([
            ['Damascus flat', '64.10'],
            ['BTC', '24.51'],
            ['ETH', '9.65'],
            ['Apple Inc.', '1.73'],
        ])
        ->and($allocation['by_asset'][1])->toBe([
            'symbol' => 'BTC',
            'name' => 'BTC',
            'type' => 'crypto',
            'value' => '32500.00000000',
            'weight_pct' => '24.51',
        ]);
});

test('weights sum to 100 within a rounding difference of 0.01', function (string $key) {
    $allocation = app(ValuationService::class)->allocation(seededLikePortfolio());

    $sum = collect($allocation[$key])->reduce(fn (string $carry, array $row) => bcadd($carry, $row['weight_pct'], 2), '0');

    expect(bccomp(bcsub('100', $sum, 2), '0.01', 2))->toBeLessThanOrEqual(0)
        ->and(bccomp(bcsub($sum, '100', 2), '0.01', 2))->toBeLessThanOrEqual(0);
})->with(['by_asset', 'by_type']);

test('groups by asset type', function () {
    $allocation = app(ValuationService::class)->allocation(seededLikePortfolio());

    // crypto = BTC 32500 + ETH 12800 = 45300, which is 34.163% of 132600
    expect($allocation['by_type'])->toBe([
        ['type' => 'real_estate', 'value' => '85000.00000000', 'weight_pct' => '64.10'],
        ['type' => 'crypto', 'value' => '45300.00000000', 'weight_pct' => '34.16'],
        ['type' => 'stock', 'value' => '2300.00000000', 'weight_pct' => '1.73'],
    ]);
});

test('an exact split rounds cleanly', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    holdAt($owner, $portfolio, globalAsset('BTC'), '1', '100');
    holdAt($owner, $portfolio, globalAsset('ETH'), '1', '100');

    $weights = collect(app(ValuationService::class)->allocation($portfolio)['by_asset'])->pluck('weight_pct')->all();

    expect($weights)->toBe(['50.00', '50.00']);
});

test('an empty portfolio has no rows and a zero total', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);

    expect(app(ValuationService::class)->allocation($portfolio))->toBe([
        'by_asset' => [],
        'by_type' => [],
        'total_value' => '0.00000000',
    ]);
});

test('holdings with no price yet get zero weights instead of dividing by zero', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    holdAt($owner, $portfolio, globalAsset('BTC'), '1', null);

    $allocation = app(ValuationService::class)->allocation($portfolio);

    expect($allocation['total_value'])->toBe('0.00000000')
        ->and($allocation['by_asset'][0]['weight_pct'])->toBe('0.00')
        ->and($allocation['by_type'][0]['weight_pct'])->toBe('0.00');
});
