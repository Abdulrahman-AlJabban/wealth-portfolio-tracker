<?php

declare(strict_types=1);

use App\Actions\LogTransaction;
use App\Actions\SetAssetPrice;
use App\Data\LogTransactionData;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/*
 * One named test per rule in BUILD_GUIDE.md section 1, so each rule can be pointed to
 * by number. The detailed cases live in the per-Action test files.
 */

beforeEach(function () {
    $this->travelTo('2026-10-25 12:00:00');
});

test('r1_users_cannot_price_global_assets', function () {
    // R1: a global asset is priced only by the sync job, so even an Owner is refused.
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $btc = globalAsset();

    expect(fn () => app(SetAssetPrice::class)->execute($owner, $portfolio, $btc, '70000'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('price_snapshots', 0);
});

test('r2_viewer_cannot_value_private_asset', function () {
    // R2: only an Editor or Owner of the owning portfolio may write a private asset's snapshots.
    [$viewer, $portfolio] = portfolioWithRole(Role::Viewer);
    $flat = Asset::create([
        'portfolio_id' => $portfolio->id,
        'type' => 'real_estate',
        'name' => 'Damascus flat',
        'currency' => 'USD',
        'price_source' => 'manual',
    ]);

    expect(fn () => app(SetAssetPrice::class)->execute($viewer, $portfolio, $flat, '85000'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('price_snapshots', 0);
});

test('r3_cannot_log_against_other_portfolios_asset', function () {
    // R3: a transaction's asset must be global or belong to the transaction's portfolio.
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    [, $otherPortfolio] = portfolioWithRole(Role::Owner);
    $foreignFlat = Asset::create([
        'portfolio_id' => $otherPortfolio->id,
        'type' => 'real_estate',
        'name' => 'Flat',
        'currency' => 'USD',
        'price_source' => 'manual',
    ]);

    expect(fn () => app(LogTransaction::class)->execute(
        $editor, $portfolio, new LogTransactionData($foreignFlat->id, TransactionType::Buy, '1', '100'),
    ))->toThrow(ValidationException::class, 'Asset not available in this portfolio.');

    $this->assertDatabaseCount('transactions', 0);
});

test('r4_cannot_oversell', function () {
    // R4: a sell or withdrawal cannot take a position below zero.
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $btc = globalAsset();
    $log = app(LogTransaction::class);
    $log->execute($editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100'));

    expect(fn () => $log->execute($editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Sell, '1.5', '100')))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('transactions', 1);
});

test('r4_cannot_oversell_with_a_backdated_sell', function () {
    // R4: the position may not be negative at any point in time, so a sell dated before its buy is refused.
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $btc = globalAsset();
    $log = app(LogTransaction::class);
    $log->execute($editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100'));

    expect(fn () => $log->execute($editor, $portfolio, new LogTransactionData(
        $btc->id, TransactionType::Sell, '1', '100', occurredAt: CarbonImmutable::parse('2026-10-18 12:00:00'),
    )))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('transactions', 1);
});

test('r5_ledger_is_append_only', function () {
    // R5: ledger rows are never updated or deleted, by the model or through any route.
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $transaction = app(LogTransaction::class)->execute(
        $editor, $portfolio, new LogTransactionData(globalAsset()->id, TransactionType::Buy, '1', '100'),
    );

    expect(fn () => $transaction->update(['quantity' => '2']))->toThrow(LogicException::class, 'Ledger rows are immutable.')
        ->and(fn () => $transaction->delete())->toThrow(LogicException::class, 'Ledger rows cannot be deleted.');

    $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'quantity' => '1.00000000']);

    $mutatingTransactionRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'transactions'))
        ->filter(fn ($route) => array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']) !== [])
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($mutatingTransactionRoutes)->toBe([]);
});

test('r5_known_gap_query_builder_update_bypasses_model_guard', function () {
    // R5 known gap: the model guard only sees Eloquent model events, so a query-builder update
    // still succeeds. The optional database triggers in actions_tasks.md Task 11 close this gap;
    // once they exist, flip this test to expect a QueryException.
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $transaction = app(LogTransaction::class)->execute(
        $editor, $portfolio, new LogTransactionData(globalAsset()->id, TransactionType::Buy, '1', '100'),
    );

    $updated = Transaction::query()->whereKey($transaction->id)->update(['note' => 'x']);

    expect($updated)->toBe(1);
    $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'note' => 'x']);
});
