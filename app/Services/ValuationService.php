<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Transaction;

/**
 * The one read model for holdings, cost basis and profit. The dashboard and the agent both use it,
 * so they can never disagree about a number.
 *
 * Profit is split into unrealized (holdings still held, at the latest price) and realized (each sale
 * or withdrawal's price minus the average cost of what left). A reversal and the row it reverses are
 * left out together, as if the mistaken trade never happened.
 *
 * Known simplifications: average-cost accounting, and every amount is assumed to be in the
 * portfolio's base currency.
 */
class ValuationService
{
    /**
     * @return array{
     *     holdings: list<array{asset_id: int, symbol: ?string, name: string, type: string, quantity: string, price: string, price_as_of: ?string, value: string, cost: string, pnl: string}>,
     *     total_value: string,
     *     total_cost: string,
     *     unrealized_pnl: string,
     *     realized_pnl: string,
     *     total_pnl: string,
     * }
     */
    public function summary(Portfolio $portfolio): array
    {
        // Three queries in total (transactions, assets, latest snapshots), however many assets are held.
        $transactions = Transaction::query()
            ->where('portfolio_id', $portfolio->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'asset_id', 'type', 'quantity', 'price', 'reverses_id']);

        // Without this, reversing one of several buys at different prices would leave a wrong cost
        // basis and a made-up realized profit, because the reversal is a sale at that buy's price.
        $reversedIds = $transactions->pluck('reverses_id')->filter()->flip();
        $transactionsByAsset = $transactions
            ->reject(fn (Transaction $transaction) => $transaction->reverses_id !== null || $reversedIds->has($transaction->id))
            ->groupBy('asset_id');

        $assets = Asset::query()
            ->with('latestSnapshot')
            ->whereIn('id', $transactionsByAsset->keys())
            ->get()
            ->keyBy('id');

        $holdings = [];
        $totalValue = '0';
        $totalCost = '0';
        $realizedPnl = '0';

        foreach ($transactionsByAsset as $assetId => $transactions) {
            $quantity = '0';
            $cost = '0';

            // Average cost: inflows add quantity × price, outflows remove the average cost of what left.
            // The difference between an outflow's price and that average cost is realized profit, which
            // stays in the total even after the asset is fully sold.
            foreach ($transactions as $transaction) {
                if (! $transaction->type->isOutflow()) {
                    $cost = bcadd($cost, bcmul($transaction->quantity, $transaction->price, 8), 8);
                    $quantity = bcadd($quantity, $transaction->quantity, 8);
                } elseif (bccomp($quantity, '0', 8) > 0) {
                    $averageCost = bcdiv($cost, $quantity, 8);
                    $realizedPnl = bcadd($realizedPnl, bcmul($transaction->quantity, bcsub($transaction->price, $averageCost, 8), 8), 8);
                    $cost = bcsub($cost, bcmul($averageCost, $transaction->quantity, 8), 8);
                    $quantity = bcsub($quantity, $transaction->quantity, 8);
                }
            }

            if (bccomp($quantity, '0', 8) <= 0) {
                continue;
            }

            $asset = $assets[$assetId];
            $snapshot = $asset->latestSnapshot;
            $price = $snapshot === null ? '0.00000000' : $snapshot->price;
            $value = bcmul($quantity, $price, 8);

            $holdings[] = [
                'asset_id' => $asset->id,
                'symbol' => $asset->symbol,
                'name' => $asset->name,
                'type' => $asset->type->value,
                'quantity' => $quantity,
                'price' => $price,
                'price_as_of' => $snapshot?->as_of?->toIso8601String(),
                'value' => $value,
                'cost' => $cost,
                'pnl' => bcsub($value, $cost, 8),
            ];

            $totalValue = bcadd($totalValue, $value, 8);
            $totalCost = bcadd($totalCost, $cost, 8);
        }

        $unrealizedPnl = bcsub($totalValue, $totalCost, 8);

        return [
            'holdings' => $holdings,
            'total_value' => bcadd($totalValue, '0', 8),
            'total_cost' => bcadd($totalCost, '0', 8),
            // Computed here so the UI never has to add or subtract money in JavaScript.
            'unrealized_pnl' => $unrealizedPnl,
            'realized_pnl' => bcadd($realizedPnl, '0', 8),
            'total_pnl' => bcadd($unrealizedPnl, $realizedPnl, 8),
        ];
    }

    /**
     * How the portfolio's value is spread, by asset and by asset type. Built from summary(),
     * so it can never disagree with the dashboard. Used by the assistant for rebalancing advice.
     *
     * @return array{
     *     by_asset: list<array{symbol: ?string, name: string, type: string, value: string, weight_pct: string}>,
     *     by_type: list<array{type: string, value: string, weight_pct: string}>,
     *     total_value: string,
     * }
     */
    public function allocation(Portfolio $portfolio): array
    {
        $summary = $this->summary($portfolio);
        $total = $summary['total_value'];

        $byAsset = [];
        $valueByType = [];

        foreach ($summary['holdings'] as $holding) {
            $byAsset[] = [
                'symbol' => $holding['symbol'],
                'name' => $holding['name'],
                'type' => $holding['type'],
                'value' => $holding['value'],
                'weight_pct' => $this->weight($holding['value'], $total),
            ];

            $valueByType[$holding['type']] = bcadd($valueByType[$holding['type']] ?? '0', $holding['value'], 8);
        }

        $byType = [];

        foreach ($valueByType as $type => $value) {
            $byType[] = [
                'type' => $type,
                'value' => $value,
                'weight_pct' => $this->weight($value, $total),
            ];
        }

        $largestFirst = fn (array $a, array $b): int => bccomp($b['value'], $a['value'], 8);
        usort($byAsset, $largestFirst);
        usort($byType, $largestFirst);

        return ['by_asset' => $byAsset, 'by_type' => $byType, 'total_value' => $total];
    }

    /**
     * value * 100 / total as a string with 2 decimals, rounded half-up. bcdiv only truncates, which
     * could make four weights sum to 99.96; rounding keeps the sum within 0.01 of 100 in practice.
     * A zero total (nothing priced yet) gives "0.00" instead of dividing by zero.
     */
    private function weight(string $value, string $total): string
    {
        if (bccomp($total, '0', 8) <= 0) {
            return '0.00';
        }

        $percent = bcdiv(bcmul($value, '100', 8), $total, 8);

        return bcadd($percent, '0.005', 2);
    }
}
