<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\PriceSnapshot;
use App\Models\Transaction;
use App\Services\ValuationService;
use Illuminate\Support\Facades\DB;

/**
 * Inserts a ledger row directly, so each test controls exact quantities, prices and dates.
 */
function valuationRow(Portfolio $portfolio, Asset $asset, TransactionType $type, string $quantity, string $price, string $occurredAt, string $fee = '0'): void
{
    Transaction::create([
        'portfolio_id' => $portfolio->id,
        'asset_id' => $asset->id,
        'type' => $type,
        'quantity' => $quantity,
        'price' => $price,
        'fee' => $fee,
        'occurred_at' => $occurredAt,
        'created_by' => $portfolio->created_by,
        'source' => 'user',
    ]);
}

function marketPrice(Asset $asset, string $price, string $asOf): void
{
    PriceSnapshot::create([
        'asset_id' => $asset->id,
        'price' => $price,
        'currency' => 'USD',
        'as_of' => $asOf,
        'source' => 'market',
    ]);
}

test('average cost removes the average price of what was sold', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();
    marketPrice($btc, '160', '2026-10-24 12:00:00');
    valuationRow($portfolio, $btc, TransactionType::Buy, '2', '100', '2026-10-01 10:00:00');
    valuationRow($portfolio, $btc, TransactionType::Buy, '2', '200', '2026-10-02 10:00:00');
    valuationRow($portfolio, $btc, TransactionType::Sell, '1', '250', '2026-10-03 10:00:00');

    $holding = app(ValuationService::class)->summary($portfolio)['holdings'][0];

    expect($holding)->toMatchArray([
        'asset_id' => $btc->id,
        'symbol' => 'BTC',
        'type' => 'crypto',
        'quantity' => '3.00000000',
        'cost' => '450.00000000',
        'price' => '160.00000000',
        'value' => '480.00000000',
        'pnl' => '30.00000000',
    ]);
});

test('fees are part of the cost of an inflow', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();
    valuationRow($portfolio, $btc, TransactionType::Buy, '2', '100', '2026-10-01 10:00:00', '5');

    expect(app(ValuationService::class)->summary($portfolio)['holdings'][0]['cost'])->toBe('205.00000000');
});

test('the price comes from the latest snapshot, not the oldest', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();
    marketPrice($btc, '60000', '2026-10-20 12:00:00');
    marketPrice($btc, '70000', '2026-10-24 12:00:00');
    marketPrice($btc, '65000', '2026-10-22 12:00:00');
    valuationRow($portfolio, $btc, TransactionType::Buy, '1', '50000', '2026-10-01 10:00:00');

    $holding = app(ValuationService::class)->summary($portfolio)['holdings'][0];

    expect($holding['price'])->toBe('70000.00000000')
        ->and($holding['price_as_of'])->toBe('2026-10-24T12:00:00+00:00');
});

test('an asset with no snapshot is valued at zero with no price date', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();
    valuationRow($portfolio, $btc, TransactionType::Buy, '1', '100', '2026-10-01 10:00:00');

    $holding = app(ValuationService::class)->summary($portfolio)['holdings'][0];

    expect($holding['price'])->toBe('0.00000000')
        ->and($holding['price_as_of'])->toBeNull()
        ->and($holding['value'])->toBe('0.00000000')
        ->and($holding['pnl'])->toBe('-100.00000000');
});

test('fully sold assets are omitted', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset('BTC');
    $eth = globalAsset('ETH');
    valuationRow($portfolio, $btc, TransactionType::Buy, '1', '100', '2026-10-01 10:00:00');
    valuationRow($portfolio, $btc, TransactionType::Sell, '1', '120', '2026-10-02 10:00:00');
    valuationRow($portfolio, $eth, TransactionType::Buy, '1', '50', '2026-10-01 10:00:00');

    $holdings = app(ValuationService::class)->summary($portfolio)['holdings'];

    expect($holdings)->toHaveCount(1)
        ->and($holdings[0]['symbol'])->toBe('ETH');
});

test('totals equal the sum of the rows', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset('BTC');
    $eth = globalAsset('ETH');
    marketPrice($btc, '65000', '2026-10-24 12:00:00');
    marketPrice($eth, '3200.5', '2026-10-24 12:00:00');
    valuationRow($portfolio, $btc, TransactionType::Buy, '0.5', '60000', '2026-10-01 10:00:00');
    valuationRow($portfolio, $eth, TransactionType::Buy, '4', '3000', '2026-10-01 10:00:00', '10');

    $summary = app(ValuationService::class)->summary($portfolio);

    // 0.5 * 65000 + 4 * 3200.5, and 0.5 * 60000 + (4 * 3000 + 10)
    expect($summary['total_value'])->toBe('45302.00000000')
        ->and($summary['total_cost'])->toBe('42010.00000000');
});

test('other portfolios do not leak into the summary', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    [, $otherPortfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();
    valuationRow($otherPortfolio, $btc, TransactionType::Buy, '5', '100', '2026-10-01 10:00:00');

    expect(app(ValuationService::class)->summary($portfolio)['holdings'])->toBe([]);
});

test('an empty portfolio returns no holdings and zero totals', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);

    expect(app(ValuationService::class)->summary($portfolio))->toBe([
        'holdings' => [],
        'total_value' => '0.00000000',
        'total_cost' => '0.00000000',
    ]);
});

test('the query count does not grow with the number of assets', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $service = app(ValuationService::class);
    $countQueries = function () use ($service, $portfolio): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->summary($portfolio);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $btc = globalAsset('BTC');
    marketPrice($btc, '1', '2026-10-24 12:00:00');
    valuationRow($portfolio, $btc, TransactionType::Buy, '1', '1', '2026-10-01 10:00:00');
    $queriesWithOneAsset = $countQueries();

    foreach (['ETH', 'SOL', 'ADA'] as $symbol) {
        $asset = globalAsset($symbol);
        marketPrice($asset, '1', '2026-10-24 12:00:00');
        valuationRow($portfolio, $asset, TransactionType::Buy, '1', '1', '2026-10-01 10:00:00');
    }

    expect($countQueries())->toBe($queriesWithOneAsset);
});
