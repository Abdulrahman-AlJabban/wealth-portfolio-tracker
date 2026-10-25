<?php

declare(strict_types=1);

namespace App\Market;

use App\Models\Asset;
use Illuminate\Support\Collection;

/**
 * Local development without an API key: each sync moves every price by up to 1.5% from its latest
 * snapshot (a random walk), so the dashboard visibly changes. Never used in production.
 */
final class FakeProvider implements PriceProvider
{
    public function quote(Collection $assets): array
    {
        return $assets->mapWithKeys(function (Asset $asset): array {
            $last = $asset->latestSnapshot?->price ?? '100';
            // Basis points from -150 to +150, applied with bcmath: last * (10000 + bps) / 10000.
            $next = bcdiv(bcmul($last, (string) (10000 + random_int(-150, 150)), 8), '10000', 8);

            return [$asset->id => bccomp($next, '0.01', 8) < 0 ? '0.01000000' : $next];
        })->all();
    }
}
