<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Positions are never stored. They are derived from the append-only ledger.
 */
class PositionService
{
    /**
     * Net quantity of one asset in one portfolio today: inflows minus outflows.
     * Uses only CASE and COALESCE so the query is portable between MySQL and PostgreSQL.
     */
    public function quantity(int $portfolioId, int $assetId): string
    {
        $quantity = DB::table('transactions')
            ->where('portfolio_id', $portfolioId)
            ->where('asset_id', $assetId)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type IN (?, ?) THEN quantity ELSE -quantity END), 0) AS quantity',
                [TransactionType::Buy->value, TransactionType::Deposit->value],
            )
            ->value('quantity');

        return bcadd((string) $quantity, '0', 8);
    }

    /**
     * R4: replays the ledger in time order and fails if the running balance ever drops below zero.
     * Checking only today's total is not enough, because a backdated sell can pass that check
     * even though the position was empty on the date of the sell.
     *
     * @throws ValidationException
     */
    public function assertNeverNegative(int $portfolioId, int $assetId): void
    {
        $transactions = Transaction::query()
            ->where('portfolio_id', $portfolioId)
            ->where('asset_id', $assetId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['type', 'quantity']);

        $balance = '0';

        foreach ($transactions as $transaction) {
            $balance = $transaction->type->isOutflow()
                ? bcsub($balance, $transaction->quantity, 8)
                : bcadd($balance, $transaction->quantity, 8);

            if (bccomp($balance, '0', 8) < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'This would make the position negative at some point in time.',
                ]);
            }
        }
    }
}
