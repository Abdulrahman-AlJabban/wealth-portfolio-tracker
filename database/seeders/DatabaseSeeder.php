<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\PriceSnapshot;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $carol = User::factory()->create(['name' => 'Carol', 'email' => 'carol@example.com']);

        $btc = Asset::firstOrCreate(
            ['portfolio_id' => null, 'symbol' => 'BTC'],
            ['type' => 'crypto', 'external_id' => 'bitcoin', 'name' => 'Bitcoin', 'price_source' => 'market']
        );
        $eth = Asset::firstOrCreate(
            ['portfolio_id' => null, 'symbol' => 'ETH'],
            ['type' => 'crypto', 'external_id' => 'ethereum', 'name' => 'Ethereum', 'price_source' => 'market']
        );
        $aapl = Asset::firstOrCreate(
            ['portfolio_id' => null, 'symbol' => 'AAPL'],
            ['type' => 'stock', 'name' => 'Apple Inc.', 'price_source' => 'market']
        );

        foreach ([[$btc, '65000'], [$eth, '3200'], [$aapl, '230']] as [$asset, $price]) {
            PriceSnapshot::updateOrCreate(
                ['asset_id' => $asset->id, 'as_of' => now()->startOfMinute(), 'source' => 'market'],
                ['price' => $price, 'currency' => 'USD'],
            );
        }

        $family = Portfolio::create(['name' => 'Family wealth', 'base_currency' => 'USD', 'created_by' => $alice->id]);
        $family->members()->createMany([
            ['user_id' => $alice->id, 'role' => 'owner'],
            ['user_id' => $bob->id, 'role' => 'editor'],
            ['user_id' => $carol->id, 'role' => 'viewer'],
        ]);

        $flat = Asset::create([
            'portfolio_id' => $family->id,
            'type' => 'real_estate',
            'name' => 'Damascus flat',
            'currency' => 'USD',
            'price_source' => 'manual',
        ]);
        PriceSnapshot::create([
            'asset_id' => $flat->id,
            'price' => '85000',
            'currency' => 'USD',
            'as_of' => now(),
            'source' => 'manual',
            'created_by' => $alice->id,
        ]);

        $rows = [
            [$btc, 'buy', '0.5', '60000'],
            [$eth, 'buy', '4', '3000'],
            [$aapl, 'buy', '10', '210'],
            [$flat, 'buy', '1', '80000'],
        ];
        foreach ($rows as [$asset, $type, $qty, $price]) {
            Transaction::create([
                'portfolio_id' => $family->id,
                'asset_id' => $asset->id,
                'type' => $type,
                'quantity' => $qty,
                'price' => $price,
                'fee' => 0,
                'occurred_at' => now()->subDays(30),
                'created_by' => $alice->id,
                'source' => 'user',
            ]);
        }

        $other = Portfolio::create(['name' => 'Carol personal', 'base_currency' => 'USD', 'created_by' => $carol->id]);
        $other->members()->create(['user_id' => $carol->id, 'role' => 'owner']);
    }
}
