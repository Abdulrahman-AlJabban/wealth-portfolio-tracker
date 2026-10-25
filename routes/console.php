<?php

use App\Market\SyncMarketPrices;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run it on demand for the demo: php artisan market:sync
Artisan::command('market:sync', function (SyncMarketPrices $sync) {
    $this->info('Updated '.$sync->run().' market prices.');
})->purpose('Fetch current prices for the global market assets');

// Every 5 minutes fits the free CoinGecko Demo key (10,000 calls a month). Needs `php artisan schedule:work`.
Schedule::command('market:sync')->everyFiveMinutes()->withoutOverlapping();
