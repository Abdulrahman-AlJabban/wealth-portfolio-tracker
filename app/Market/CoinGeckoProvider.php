<?php

declare(strict_types=1);

namespace App\Market;

use App\Models\Asset;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * CoinGecko simple/price, with a free Demo key. One request prices every asset that has a
 * CoinGecko id (`external_id`), for example bitcoin, ethereum, and apple-xstock for AAPL.
 *
 * AAPL is priced through Apple xStock (AAPLX), a token backed 1:1 by Apple shares. It tracks the
 * stock but trades around the clock, so it can differ from the exchange price.
 */
final class CoinGeckoProvider implements PriceProvider
{
    public function __construct(private string $key, private string $baseUrl) {}

    /**
     * @throws RequestException when CoinGecko answers with an error (for example 401 bad key, 429 over quota)
     */
    public function quote(Collection $assets): array
    {
        $priced = $assets->filter(fn (Asset $asset): bool => filled($asset->external_id) && $asset->currency === 'USD');

        if ($priced->isEmpty()) {
            return [];
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders(['x-cg-demo-api-key' => $this->key])
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 500, throw: false)
            ->get('simple/price', [
                'ids' => $priced->pluck('external_id')->unique()->implode(','),
                'vs_currencies' => 'usd',
                'precision' => 'full',
            ])
            ->throw()
            ->json();

        $prices = [];

        foreach ($priced as $asset) {
            $usd = $response[$asset->external_id]['usd'] ?? null;

            // JSON numbers arrive as floats; format straight to an 8-decimal string and never compute with it.
            if (is_int($usd) || is_float($usd)) {
                $prices[$asset->id] = number_format((float) $usd, 8, '.', '');
            }
        }

        return $prices;
    }
}
