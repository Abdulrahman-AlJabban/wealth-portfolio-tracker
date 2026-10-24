<?php

use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\PriceSnapshot;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('membership is unique per user and portfolio', function () {
    $user = User::factory()->create();
    $p = Portfolio::create(['name' => 'P', 'base_currency' => 'USD', 'created_by' => $user->id]);
    $p->members()->create(['user_id' => $user->id, 'role' => 'owner']);

    expect(fn () => $p->members()->create(['user_id' => $user->id, 'role' => 'viewer']))
        ->toThrow(QueryException::class);
});

test('a transaction can only be reversed once at the database level', function () {
    $user = User::factory()->create();
    $p = Portfolio::create(['name' => 'P', 'base_currency' => 'USD', 'created_by' => $user->id]);
    $asset = Asset::create(['portfolio_id' => $p->id, 'type' => 'stock', 'name' => 'Test', 'price_source' => 'manual']);

    $original = Transaction::create([
        'portfolio_id' => $p->id,
        'asset_id' => $asset->id,
        'type' => 'buy',
        'quantity' => '10',
        'price' => '100',
        'fee' => '0',
        'occurred_at' => now(),
        'created_by' => $user->id,
        'source' => 'user',
    ]);

    // First reversal succeeds
    Transaction::create([
        'portfolio_id' => $p->id,
        'asset_id' => $asset->id,
        'type' => 'sell',
        'quantity' => '10',
        'price' => '100',
        'fee' => '0',
        'occurred_at' => now(),
        'created_by' => $user->id,
        'source' => 'user',
        'reverses_id' => $original->id,
    ]);

    // Second reversal should fail due to unique constraint on reverses_id
    expect(fn () => Transaction::create([
        'portfolio_id' => $p->id,
        'asset_id' => $asset->id,
        'type' => 'sell',
        'quantity' => '10',
        'price' => '100',
        'fee' => '0',
        'occurred_at' => now(),
        'created_by' => $user->id,
        'source' => 'user',
        'reverses_id' => $original->id,
    ]))->toThrow(QueryException::class);
});

test('price snapshot is unique per asset, time and source', function () {
    $asset = Asset::create(['portfolio_id' => null, 'type' => 'crypto', 'symbol' => 'BTC', 'name' => 'Bitcoin', 'price_source' => 'market']);
    $asOf = now()->startOfMinute();

    PriceSnapshot::create([
        'asset_id' => $asset->id,
        'price' => '65000',
        'currency' => 'USD',
        'as_of' => $asOf,
        'source' => 'market',
    ]);

    // Duplicate should fail due to unique constraint on (asset_id, as_of, source)
    expect(fn () => PriceSnapshot::create([
        'asset_id' => $asset->id,
        'price' => '66000',
        'currency' => 'USD',
        'as_of' => $asOf,
        'source' => 'market',
    ]))->toThrow(QueryException::class);
});

test('money keeps eight decimal places', function () {
    $user = User::factory()->create();
    $p = Portfolio::create(['name' => 'P', 'base_currency' => 'USD', 'created_by' => $user->id]);
    $asset = Asset::create(['portfolio_id' => $p->id, 'type' => 'stock', 'name' => 'Test', 'price_source' => 'manual']);

    $tx = Transaction::create([
        'portfolio_id' => $p->id,
        'asset_id' => $asset->id,
        'type' => 'buy',
        'quantity' => '0.00000001',
        'price' => '100',
        'fee' => '0',
        'occurred_at' => now(),
        'created_by' => $user->id,
        'source' => 'user',
    ]);

    $tx->refresh();

    expect((string) $tx->quantity)->toBe('0.00000001');
});
