<?php

declare(strict_types=1);

namespace App\Market;

use App\Actions\LogTransaction;
use App\Enums\PriceSource;
use App\Models\Asset;
use App\Models\PriceSnapshot;

/**
 * R1: the only writer of market prices. Fetches prices for every global market asset and stores
 * one snapshot per asset per minute.
 *
 * Idempotent: the snapshot time is rounded down to the minute and written with an upsert on the
 * unique (asset_id, as_of, source) key, so running it twice in the same minute updates the same
 * row instead of adding a duplicate.
 */
final class SyncMarketPrices
{
    public function __construct(private PriceProvider $provider) {}

    /**
     * @return int how many assets got a new price
     */
    public function run(): int
    {
        $assets = Asset::query()
            ->with('latestSnapshot')
            ->whereNull('portfolio_id')
            ->where('price_source', PriceSource::Market)
            ->get();

        $byId = $assets->keyBy('id');
        $asOf = now()->startOfMinute();

        $rows = [];

        foreach ($this->provider->quote($assets) as $assetId => $price) {
            // Only assets we asked for, and only well-formed positive decimals.
            if (! $byId->has($assetId) || preg_match(LogTransaction::DECIMAL_PATTERN, $price) !== 1 || bccomp($price, '0', 8) <= 0) {
                continue;
            }

            $rows[] = [
                'asset_id' => $assetId,
                'price' => $price,
                'currency' => $byId[$assetId]->currency,
                'as_of' => $asOf,
                'source' => PriceSource::Market->value,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            PriceSnapshot::upsert($rows, ['asset_id', 'as_of', 'source'], ['price', 'updated_at']);
        }

        return count($rows);
    }
}
