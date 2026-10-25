<?php

namespace App\Providers;

use App\Market\CoinGeckoProvider;
use App\Market\FakeProvider;
use App\Market\PriceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Market prices: CoinGecko in real use, a local random walk when no key is set up.
        $this->app->bind(PriceProvider::class, fn () => match (config('market.driver')) {
            'coingecko' => new CoinGeckoProvider((string) config('market.coingecko.key'), (string) config('market.coingecko.url')),
            default => new FakeProvider,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Vite::prefetch(concurrency: 3);
    }
}
