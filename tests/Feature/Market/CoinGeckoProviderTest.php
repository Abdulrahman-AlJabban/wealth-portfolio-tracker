<?php

declare(strict_types=1);

use App\Market\CoinGeckoProvider;
use App\Models\Asset;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function geckoAsset(string $symbol, ?string $externalId, string $currency = 'USD'): Asset
{
    return Asset::create([
        'portfolio_id' => null, 'type' => 'crypto', 'symbol' => $symbol, 'external_id' => $externalId,
        'name' => $symbol, 'currency' => $currency, 'price_source' => 'market',
    ]);
}

function gecko(): CoinGeckoProvider
{
    return new CoinGeckoProvider('demo-test-key', 'https://api.coingecko.com/api/v3');
}

test('prices every asset in one batched request, as 8-decimal strings', function () {
    Http::fake(['api.coingecko.com/*' => Http::response([
        'bitcoin' => ['usd' => 67123.45],
        'ethereum' => ['usd' => 3200],
        'apple-xstock' => ['usd' => 228.3125],
    ])]);
    $btc = geckoAsset('BTC', 'bitcoin');
    $eth = geckoAsset('ETH', 'ethereum');
    $aapl = geckoAsset('AAPL', 'apple-xstock');

    $prices = gecko()->quote(collect([$btc, $eth, $aapl]));

    expect($prices)->toBe([
        $btc->id => '67123.45000000',
        $eth->id => '3200.00000000',
        $aapl->id => '228.31250000',
    ]);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.coingecko.com/api/v3/simple/price')
        && $request['ids'] === 'bitcoin,ethereum,apple-xstock'
        && $request['vs_currencies'] === 'usd'
        && $request['precision'] === 'full'
        && $request->hasHeader('x-cg-demo-api-key', 'demo-test-key'));
});

test('assets without a CoinGecko id or not in USD are not requested', function () {
    Http::fake();

    $prices = gecko()->quote(collect([geckoAsset('XYZ', null), geckoAsset('EURC', 'euro-coin', 'EUR')]));

    expect($prices)->toBe([]);
    Http::assertNothingSent();
});

test('a coin missing from the response is skipped, not priced at zero', function () {
    Http::fake(['api.coingecko.com/*' => Http::response(['bitcoin' => ['usd' => 67000]])]);
    $btc = geckoAsset('BTC', 'bitcoin');
    $missing = geckoAsset('NOPE', 'no-such-coin');

    expect(gecko()->quote(collect([$btc, $missing])))->toBe([$btc->id => '67000.00000000']);
});

test('an error from CoinGecko throws, so nothing partial is stored', function (int $status) {
    Http::fake(['api.coingecko.com/*' => Http::response(['error' => 'nope'], $status)]);
    // The provider retries twice with a back-off; skip the waits.
    Sleep::fake();

    expect(fn () => gecko()->quote(collect([geckoAsset('BTC', 'bitcoin')])))->toThrow(RequestException::class);
})->with(['bad key' => 401, 'over quota' => 429, 'server error' => 500]);
