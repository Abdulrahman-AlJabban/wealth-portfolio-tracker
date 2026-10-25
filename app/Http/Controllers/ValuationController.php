<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SetAssetPrice;
use App\Http\Requests\StoreValuationRequest;
use App\Models\Asset;
use App\Models\Portfolio;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class ValuationController extends Controller
{
    /**
     * Scoped binding resolves {asset} through Portfolio::assets(), which holds only private assets,
     * so a global asset's URL is already a 404. SetAssetPrice still enforces R1 and R2 itself.
     */
    public function store(StoreValuationRequest $request, Portfolio $portfolio, Asset $asset, SetAssetPrice $setAssetPrice): RedirectResponse
    {
        $asOf = $request->validated('as_of');

        $setAssetPrice->execute(
            $request->user(),
            $portfolio,
            $asset,
            $request->validated('price'),
            $asOf === null ? null : CarbonImmutable::parse($asOf),
        );

        return back()->with('success', 'Valuation saved.');
    }
}
