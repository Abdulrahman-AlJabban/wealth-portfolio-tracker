<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AssetType;
use App\Enums\PriceSource;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds a private, manually valued asset (a flat, a bank account) to one portfolio.
 * It can never create a global market asset: portfolio_id and price_source are fixed here,
 * not taken from the caller.
 */
final class CreatePrivateAsset
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $user, Portfolio $portfolio, string $name, AssetType $type): Asset
    {
        Gate::forUser($user)->authorize('write', $portfolio);

        $name = trim($name);

        $this->validate($name, $type);

        return DB::transaction(function () use ($portfolio, $name, $type) {
            // Serializes asset creation on this portfolio, so two requests with the same name
            // cannot both pass the check below and the second fail on the unique index.
            Portfolio::query()->whereKey($portfolio->id)->lockForUpdate()->firstOrFail();

            // Uses the column's collation, so the comparison is case-insensitive like the index.
            if (Asset::query()->where('portfolio_id', $portfolio->id)->where('name', $name)->exists()) {
                throw ValidationException::withMessages([
                    'name' => 'This portfolio already has an asset with that name.',
                ]);
            }

            return Asset::create([
                'portfolio_id' => $portfolio->id,
                'type' => $type,
                'symbol' => null,
                'external_id' => null,
                'name' => $name,
                'currency' => $portfolio->base_currency,
                'price_source' => PriceSource::Manual,
            ]);
        });
    }

    /**
     * @throws ValidationException
     */
    private function validate(string $name, AssetType $type): void
    {
        $errors = [];

        if (Str::length($name) < 1 || Str::length($name) > 100) {
            $errors['name'] = 'The name must be between 1 and 100 characters.';
        }

        // Stocks and crypto are market assets with a provider price, so they are global only.
        if (! in_array($type, [AssetType::RealEstate, AssetType::Cash], true)) {
            $errors['type'] = 'Only real estate and cash can be added as private assets.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
