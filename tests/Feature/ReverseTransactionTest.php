<?php

declare(strict_types=1);

use App\Actions\LogTransaction;
use App\Actions\ReverseTransaction;
use App\Data\LogTransactionData;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PositionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Logs a row through the real write path, so every original in these tests is a valid ledger row.
 */
function logged(User $user, Portfolio $portfolio, Asset $asset, TransactionType $type, string $quantity): Transaction
{
    return app(LogTransaction::class)->execute(
        $user, $portfolio, new LogTransactionData($asset->id, $type, $quantity, '100'),
    );
}

beforeEach(function () {
    $this->travelTo('2026-10-25 12:00:00');
});

test('reversing a buy appends an opposite row and restores the position', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $btc = globalAsset();
    logged($editor, $portfolio, $btc, TransactionType::Buy, '2');
    $buy = logged($editor, $portfolio, $btc, TransactionType::Buy, '0.5');

    $reversal = app(ReverseTransaction::class)->execute($editor, $portfolio, $buy);

    $this->assertDatabaseHas('transactions', [
        'id' => $reversal->id,
        'portfolio_id' => $portfolio->id,
        'asset_id' => $btc->id,
        'type' => 'sell',
        'quantity' => '0.50000000',
        'price' => '100.00000000',
        'occurred_at' => '2026-10-25 12:00:00',
        'created_by' => $editor->id,
        'source' => 'user',
        'reverses_id' => $buy->id,
        'note' => "Reversal of #{$buy->id}",
    ]);
    expect(app(PositionService::class)->quantity($portfolio->id, $btc->id))->toBe('2.00000000');
});

test('reversing a sell restores the position', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $btc = globalAsset();
    logged($editor, $portfolio, $btc, TransactionType::Buy, '1');
    $sell = logged($editor, $portfolio, $btc, TransactionType::Sell, '1');

    $reversal = app(ReverseTransaction::class)->execute($editor, $portfolio, $sell);

    expect($reversal->type)->toBe(TransactionType::Buy)
        ->and(app(PositionService::class)->quantity($portfolio->id, $btc->id))->toBe('1.00000000');
});

test('the original row is left untouched', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $buy = logged($editor, $portfolio, globalAsset(), TransactionType::Buy, '1');

    app(ReverseTransaction::class)->execute($editor, $portfolio, $buy);

    expect($buy->fresh()->only(['type', 'quantity', 'reverses_id', 'updated_at']))
        ->toEqual($buy->only(['type', 'quantity', 'reverses_id', 'updated_at']));
});

test('a row cannot be reversed twice', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $buy = logged($editor, $portfolio, globalAsset(), TransactionType::Buy, '1');
    $reverse = app(ReverseTransaction::class);
    $reverse->execute($editor, $portfolio, $buy);

    expect(fn () => $reverse->execute($editor, $portfolio, $buy))
        ->toThrow(ValidationException::class, 'This transaction has already been reversed.');

    $this->assertDatabaseCount('transactions', 2);
});

test('a reversal cannot itself be reversed', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $buy = logged($editor, $portfolio, globalAsset(), TransactionType::Buy, '1');
    $reverse = app(ReverseTransaction::class);
    $reversal = $reverse->execute($editor, $portfolio, $buy);

    expect(fn () => $reverse->execute($editor, $portfolio, $reversal))
        ->toThrow(ValidationException::class, 'A reversal cannot be reversed.');

    $this->assertDatabaseCount('transactions', 2);
});

test('reversing a buy that was already sold is rejected and inserts nothing', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $btc = globalAsset();
    $buy = logged($editor, $portfolio, $btc, TransactionType::Buy, '1');
    logged($editor, $portfolio, $btc, TransactionType::Sell, '1');

    expect(fn () => app(ReverseTransaction::class)->execute($editor, $portfolio, $buy))
        ->toThrow(ValidationException::class, 'This would make the position negative at some point in time.');

    $this->assertDatabaseCount('transactions', 2);
});

test('viewers cannot reverse', function () {
    [$viewer, $portfolio] = portfolioWithRole(Role::Viewer);
    $owner = User::find($portfolio->created_by);
    $buy = logged($owner, $portfolio, globalAsset(), TransactionType::Buy, '1');

    expect(fn () => app(ReverseTransaction::class)->execute($viewer, $portfolio, $buy))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('transactions', 1);
});

test('another portfolio\'s row cannot be reversed through this portfolio', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    [$otherOwner, $otherPortfolio] = portfolioWithRole(Role::Owner);
    $foreignBuy = logged($otherOwner, $otherPortfolio, globalAsset(), TransactionType::Buy, '1');

    expect(fn () => app(ReverseTransaction::class)->execute($editor, $portfolio, $foreignBuy))
        ->toThrow(ValidationException::class, 'Transaction not found.');

    $this->assertDatabaseCount('transactions', 1);
});
