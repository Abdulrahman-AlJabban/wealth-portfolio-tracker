<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreatePrivateAsset;
use App\Enums\AssetType;
use App\Http\Requests\StoreAssetRequest;
use App\Models\Portfolio;
use Illuminate\Http\RedirectResponse;

class AssetController extends Controller
{
    public function store(StoreAssetRequest $request, Portfolio $portfolio, CreatePrivateAsset $createPrivateAsset): RedirectResponse
    {
        $createPrivateAsset->execute(
            $request->user(),
            $portfolio,
            $request->validated('name'),
            AssetType::from($request->validated('type')),
        );

        return back()->with('success', 'Asset added.');
    }
}
