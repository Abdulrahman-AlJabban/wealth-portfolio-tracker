<?php

declare(strict_types=1);

namespace App\Market;

use App\Models\Asset;
use Illuminate\Support\Collection;

/**
 * A source of current market prices. The sync asks the bound provider for prices of the global
 * market assets; a provider returns only the ones it can price, so adding a source (for example a
 * stock-data provider) means one more class, not a change to the sync.
 */
interface PriceProvider
{
    /**
     * @param  Collection<int, Asset>  $assets
     * @return array<int, string> asset id => price as a decimal string with 8 places
     */
    public function quote(Collection $assets): array;
}
