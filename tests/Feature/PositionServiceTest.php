<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Services\PositionService;
use Illuminate\Validation\ValidationException;

/**
 * Inserts a ledger row directly. LogTransaction does not exist yet, and these tests
 * must be able to arrange states (like a backdated oversell) that the Action would refuse.
 */
function ledgerRow(Portfolio $portfolio, Asset $asset, TransactionType $type, string $quantity, string $occurredAt): Transaction
{
    return Transaction::create([
        'portfolio_id' => $portfolio->id,
        'asset_id' => $asset->id,
        'type' => $type,
        'quantity' => $quantity,
        'price' => '100',
        'occurred_at' => $occurredAt,
        'created_by' => $portfolio->created_by,
        'source' => 'user',
    ]);
}

beforeEach(function () {
    $this->travelTo('2026-10-25 12:00:00');
});

test('quantity is inflows minus outflows', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();
    ledgerRow($portfolio, $btc, TransactionType::Buy, '1', '2026-10-20 10:00:00');
    ledgerRow($portfolio, $btc, TransactionType::Sell, '0.4', '2026-10-21 10:00:00');

    expect(app(PositionService::class)->quantity($portfolio->id, $btc->id))->toBe('0.60000000');
});

test('quantity keeps all eight decimal places', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();
    ledgerRow($portfolio, $btc, TransactionType::Buy, '1', '2026-10-20 10:00:00');
    ledgerRow($portfolio, $btc, TransactionType::Sell, '0.99999999', '2026-10-21 10:00:00');

    expect(app(PositionService::class)->quantity($portfolio->id, $btc->id))->toBe('0.00000001');
});

test('quantity is zero when there are no transactions', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();

    expect(app(PositionService::class)->quantity($portfolio->id, $btc->id))->toBe('0.00000000');
});

test('quantity ignores other portfolios and other assets', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    [, $otherPortfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset('BTC');
    $eth = globalAsset('ETH');
    ledgerRow($portfolio, $btc, TransactionType::Buy, '2', '2026-10-20 10:00:00');
    ledgerRow($otherPortfolio, $btc, TransactionType::Buy, '5', '2026-10-20 10:00:00');
    ledgerRow($portfolio, $eth, TransactionType::Buy, '7', '2026-10-20 10:00:00');

    expect(app(PositionService::class)->quantity($portfolio->id, $btc->id))->toBe('2.00000000');
});

test('a sell dated before the buy that covers it is rejected', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();
    ledgerRow($portfolio, $btc, TransactionType::Buy, '1', '2026-10-25 10:00:00');
    ledgerRow($portfolio, $btc, TransactionType::Sell, '1', '2026-10-18 10:00:00');

    expect(fn () => app(PositionService::class)->assertNeverNegative($portfolio->id, $btc->id))
        ->toThrow(ValidationException::class, 'This would make the position negative at some point in time.');
});

test('a sell dated after the buy that covers it is accepted', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();
    ledgerRow($portfolio, $btc, TransactionType::Buy, '1', '2026-10-18 10:00:00');
    ledgerRow($portfolio, $btc, TransactionType::Sell, '1', '2026-10-25 10:00:00');

    app(PositionService::class)->assertNeverNegative($portfolio->id, $btc->id);

    expect(app(PositionService::class)->quantity($portfolio->id, $btc->id))->toBe('0.00000000');
});

test('rows at the same time are replayed in insert order', function () {
    [, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();
    ledgerRow($portfolio, $btc, TransactionType::Sell, '1', '2026-10-20 10:00:00');
    ledgerRow($portfolio, $btc, TransactionType::Buy, '1', '2026-10-20 10:00:00');

    expect(fn () => app(PositionService::class)->assertNeverNegative($portfolio->id, $btc->id))
        ->toThrow(ValidationException::class);
});
