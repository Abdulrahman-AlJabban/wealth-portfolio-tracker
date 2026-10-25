<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PriceSource;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\PriceSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Manual valuation of a private asset, such as a flat or a bank account.
 * R1: global market assets are priced only by the sync job, never by a user.
 * R2: a private asset is priced only by an Editor or Owner of the portfolio that owns it.
 */
final class SetAssetPrice
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $user, Portfolio $portfolio, Asset $asset, string $price, ?CarbonImmutable $asOf = null): PriceSnapshot
    {
        Gate::forUser($user)->authorize('write', $portfolio);

        $this->validate($price, $asOf);

        if ($asset->isGlobal() || $asset->price_source !== PriceSource::Manual || $asset->portfolio_id !== $portfolio->id) {
            throw new AuthorizationException('This asset cannot be priced manually.');
        }

        // as_of is stored to the second and is part of the unique key, so a second valuation for the
        // same moment replaces the first instead of failing on the index.
        return DB::transaction(fn () => PriceSnapshot::updateOrCreate(
            [
                'asset_id' => $asset->id,
                'as_of' => $asOf ?? now(),
                'source' => PriceSource::Manual->value,
            ],
            [
                'price' => $price,
                'currency' => $asset->currency,
                'created_by' => $user->id,
            ],
        ));
    }

    /**
     * @throws ValidationException
     */
    private function validate(string $price, ?CarbonImmutable $asOf): void
    {
        $errors = [];

        if (preg_match(LogTransaction::DECIMAL_PATTERN, $price) !== 1 || bccomp($price, '0', 8) <= 0) {
            $errors['price'] = 'Price must be a positive decimal with at most 8 decimal places.';
        }

        // The latest as_of wins as the current price, so a future date would pin the valuation until then.
        if ($asOf?->isFuture()) {
            $errors['as_of'] = 'The valuation date cannot be in the future.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
