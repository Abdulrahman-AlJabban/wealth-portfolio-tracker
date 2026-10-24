<?php

use App\Enums\Role;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A fresh portfolio with a separate owner, plus a user holding $role in it.
 * For Role::Owner the returned user is the owner.
 *
 * @return array{0: User, 1: Portfolio}
 */
function portfolioWithRole(Role $role): array
{
    $owner = User::factory()->create();
    $portfolio = Portfolio::create([
        'name' => 'Test Portfolio',
        'base_currency' => 'USD',
        'created_by' => $owner->id,
    ]);
    $portfolio->members()->create(['user_id' => $owner->id, 'role' => Role::Owner->value]);

    if ($role === Role::Owner) {
        return [$owner, $portfolio];
    }

    $user = User::factory()->create();
    $portfolio->members()->create(['user_id' => $user->id, 'role' => $role->value]);

    return [$user, $portfolio];
}

function globalAsset(string $symbol = 'BTC'): Asset
{
    return Asset::create([
        'portfolio_id' => null,
        'type' => 'crypto',
        'symbol' => $symbol,
        'external_id' => strtolower($symbol),
        'name' => $symbol,
        'currency' => 'USD',
        'price_source' => 'market',
    ]);
}
