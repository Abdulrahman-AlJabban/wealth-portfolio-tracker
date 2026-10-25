<?php

declare(strict_types=1);

use App\Actions\LogTransaction;
use App\Data\LogTransactionData;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Market\PriceProvider;
use App\Market\SyncMarketPrices;
use App\Models\Asset;
use App\Models\PriceSnapshot;
use App\Services\ValuationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;

/**
 * Makes the sync use fixed prices, given as symbol => price.
 *
 * @param  array<string, string>  $pricesBySymbol
 */
function pricesWillBe(array $pricesBySymbol): void
{
    app()->instance(PriceProvider::class, new class($pricesBySymbol) implements PriceProvider
    {
        /** @param array<string, string> $pricesBySymbol */
        public function __construct(private array $pricesBySymbol) {}

        public function quote(Collection $assets): array
        {
            return $assets->filter(fn (Asset $asset) => isset($this->pricesBySymbol[$asset->symbol]))
                ->mapWithKeys(fn (Asset $asset) => [$asset->id => $this->pricesBySymbol[$asset->symbol]])
                ->all();
        }
    });
}

beforeEach(function () {
    $this->travelTo('2026-10-25 14:03:27');
});

test('stores a market snapshot for each global market asset, at the start of the minute', function () {
    $btc = globalAsset('BTC');
    pricesWillBe(['BTC' => '67123.45000000']);

    $updated = app(SyncMarketPrices::class)->run();

    expect($updated)->toBe(1);
    $this->assertDatabaseHas('price_snapshots', [
        'asset_id' => $btc->id,
        'price' => '67123.45000000',
        'currency' => 'USD',
        'as_of' => '2026-10-25 14:03:00',
        'source' => 'market',
        'created_by' => null,
    ]);
});

test('running twice in the same minute updates the same row (idempotent)', function () {
    $btc = globalAsset('BTC');

    pricesWillBe(['BTC' => '67000.00000000']);
    app(SyncMarketPrices::class)->run();
    pricesWillBe(['BTC' => '67100.00000000']);
    app(SyncMarketPrices::class)->run();

    expect(PriceSnapshot::where('asset_id', $btc->id)->count())->toBe(1)
        ->and(PriceSnapshot::where('asset_id', $btc->id)->value('price'))->toBe('67100.00000000');
});

test('a later minute adds a new snapshot, which becomes the current price', function () {
    $btc = globalAsset('BTC');
    pricesWillBe(['BTC' => '67000.00000000']);
    app(SyncMarketPrices::class)->run();

    $this->travel(5)->minutes();
    pricesWillBe(['BTC' => '68000.00000000']);
    app(SyncMarketPrices::class)->run();

    expect(PriceSnapshot::where('asset_id', $btc->id)->count())->toBe(2)
        ->and($btc->fresh()->latestSnapshot->price)->toBe('68000.00000000');
});

test('private manually valued assets are never touched (R1 and R2)', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    Asset::create(['portfolio_id' => $portfolio->id, 'type' => 'real_estate', 'symbol' => 'FLAT', 'name' => 'Flat', 'currency' => 'USD', 'price_source' => 'manual']);
    pricesWillBe(['FLAT' => '1.00000000']);

    expect(app(SyncMarketPrices::class)->run())->toBe(0);
    $this->assertDatabaseCount('price_snapshots', 0);
});

test('malformed, zero or negative prices from a provider are skipped', function (string $price) {
    globalAsset('BTC');
    pricesWillBe(['BTC' => $price]);

    expect(app(SyncMarketPrices::class)->run())->toBe(0);
    $this->assertDatabaseCount('price_snapshots', 0);
})->with(['0.00000000', '-5.00000000', 'abc', '1e3']);

test('the dashboard summary picks up the synced price', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset('BTC');
    app(LogTransaction::class)->execute($owner, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '0.5', '60000'));
    pricesWillBe(['BTC' => '70000.00000000']);

    app(SyncMarketPrices::class)->run();

    expect(app(ValuationService::class)->summary($portfolio)['total_value'])->toBe('35000.00000000');
});

test('market:sync reports how many prices it updated', function () {
    globalAsset('BTC');
    globalAsset('ETH');
    pricesWillBe(['BTC' => '67000.00000000', 'ETH' => '3200.00000000']);

    $this->artisan('market:sync')->expectsOutput('Updated 2 market prices.')->assertSuccessful();
});

test('market:sync is scheduled every five minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'market:sync'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
