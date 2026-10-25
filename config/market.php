<?php

declare(strict_types=1);

/*
 * Market prices for global assets (R1: only the sync writes them).
 */
return [

    // "coingecko" for live prices, "fake" for a local random walk that needs no key.
    'driver' => env('MARKET_DRIVER', 'fake'),

    'coingecko' => [
        // A free Demo key allows 10,000 calls a month; one batched call every 5 minutes is about 8,640.
        'key' => env('COINGECKO_API_KEY'),
        'url' => env('COINGECKO_URL', 'https://api.coingecko.com/api/v3'),
    ],

];
