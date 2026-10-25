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
 * Known simplifications: average-cost accounting, reversals are treated as ordinary opposite rows,
 * and every amount is assumed to be in the portfolio's base currency.
 */
class ValuationService
{
    /**
     * @return array{
     *     holdings: list<array{asset_id: int, symbol: ?string, name: string, type: string, quantity: string, price: string, price_as_of: ?string, value: string, cost: string, pnl: string}>,
     *     total_value: string,
     *     total_cost: string,
     *     total_pnl: string,
     * }
     */
    public function summary(Portfolio $portfolio): array
    {
        // Three queries in total (transactions, assets, latest snapshots), however many assets are held.
        $transactionsByAsset = Transaction::query()
            ->where('portfolio_id', $portfolio->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['asset_id', 'type', 'quantity', 'price', 'fee'])
            ->groupBy('asset_id');

        $assets = Asset::query()
            ->with('latestSnapshot')
            ->whereIn('id', $transactionsByAsset->keys())
            ->get()
            ->keyBy('id');

        $holdings = [];
        $totalValue = '0';
        $totalCost = '0';

        foreach ($transactionsByAsset as $assetId => $transactions) {
            $quantity = '0';
            $cost = '0';

            // Average cost: inflows add their full cost, outflows remove the average cost of what left.
            foreach ($transactions as $transaction) {
                if (! $transaction->type->isOutflow()) {
                    $purchaseCost = bcadd(bcmul($transaction->quantity, $transaction->price, 8), $transaction->fee, 8);
                    $cost = bcadd($cost, $purchaseCost, 8);
                    $quantity = bcadd($quantity, $transaction->quantity, 8);
                } elseif (bccomp($quantity, '0', 8) > 0) {
                    $averageCost = bcdiv($cost, $quantity, 8);
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

        return [
            'holdings' => $holdings,
            'total_value' => bcadd($totalValue, '0', 8),
            'total_cost' => bcadd($totalCost, '0', 8),
            // Computed here so the UI never has to subtract money in JavaScript.
            'total_pnl' => bcsub($totalValue, $totalCost, 8),
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
