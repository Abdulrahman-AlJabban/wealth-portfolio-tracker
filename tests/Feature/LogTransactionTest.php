<?php

declare(strict_types=1);

use App\Actions\LogTransaction;
use App\Data\LogTransactionData;
use App\Enums\Role;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo('2026-10-25 12:00:00');
});

describe('authorization', function () {
    test('editors and owners can log a buy', function (Role $role) {
        [$user, $portfolio] = portfolioWithRole($role);
        $btc = globalAsset();

        $transaction = app(LogTransaction::class)->execute(
            $user, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '0.5', '60000', '1.25', note: 'First buy'),
        );

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'portfolio_id' => $portfolio->id,
            'asset_id' => $btc->id,
            'type' => 'buy',
            'quantity' => '0.50000000',
            'price' => '60000.00000000',
            'fee' => '1.25000000',
            'occurred_at' => '2026-10-25 12:00:00',
            'created_by' => $user->id,
            'source' => 'user',
            'idempotency_key' => null,
            'note' => 'First buy',
        ]);
    })->with([Role::Editor, Role::Owner]);

    test('viewers cannot log a transaction', function () {
        [$viewer, $portfolio] = portfolioWithRole(Role::Viewer);
        $btc = globalAsset();

        expect(fn () => app(LogTransaction::class)->execute(
            $viewer, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100'),
        ))->toThrow(AuthorizationException::class);

        $this->assertDatabaseCount('transactions', 0);
    });

    test('non-members cannot log a transaction', function () {
        [, $portfolio] = portfolioWithRole(Role::Owner);
        $btc = globalAsset();

        expect(fn () => app(LogTransaction::class)->execute(
            User::factory()->create(), $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100'),
        ))->toThrow(AuthorizationException::class);

        $this->assertDatabaseCount('transactions', 0);
    });

    test('the agent source is recorded', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();

        $transaction = app(LogTransaction::class)->execute(
            $editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100'), TransactionSource::Agent,
        );

        expect($transaction->fresh()->source)->toBe(TransactionSource::Agent);
    });
});

describe('assets (R3)', function () {
    test('another portfolio\'s private asset is rejected', function () {
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

    test('a missing asset gets the same message as a foreign one', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);

        expect(fn () => app(LogTransaction::class)->execute(
            $editor, $portfolio, new LogTransactionData(999999, TransactionType::Buy, '1', '100'),
        ))->toThrow(ValidationException::class, 'Asset not available in this portfolio.');
    });

    test('the portfolio\'s own private asset is accepted', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $flat = Asset::create([
            'portfolio_id' => $portfolio->id,
            'type' => 'real_estate',
            'name' => 'Flat',
            'currency' => 'USD',
            'price_source' => 'manual',
        ]);

        app(LogTransaction::class)->execute($editor, $portfolio, new LogTransactionData($flat->id, TransactionType::Buy, '1', '80000'));

        $this->assertDatabaseHas('transactions', ['portfolio_id' => $portfolio->id, 'asset_id' => $flat->id]);
    });

    test('an asset in another currency is rejected', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $sap = Asset::create([
            'portfolio_id' => null,
            'type' => 'stock',
            'symbol' => 'SAP',
            'name' => 'SAP SE',
            'currency' => 'EUR',
            'price_source' => 'market',
        ]);

        expect(fn () => app(LogTransaction::class)->execute(
            $editor, $portfolio, new LogTransactionData($sap->id, TransactionType::Buy, '1', '100'),
        ))->toThrow(ValidationException::class, 'Only USD assets can be traded in this single-currency prototype.');

        $this->assertDatabaseCount('transactions', 0);
    });
});

describe('positions (R4)', function () {
    test('selling more than is held is rejected and inserts nothing', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();
        $log = app(LogTransaction::class);
        $log->execute($editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100'));

        expect(fn () => $log->execute($editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Sell, '1.5', '100')))
            ->toThrow(ValidationException::class, 'This would make the position negative at some point in time.');

        $this->assertDatabaseCount('transactions', 1);
    });

    test('a sell backdated to before the covering buy is rejected', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();
        $log = app(LogTransaction::class);
        $log->execute($editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100'));

        expect(fn () => $log->execute($editor, $portfolio, new LogTransactionData(
            $btc->id, TransactionType::Sell, '1', '100', occurredAt: CarbonImmutable::parse('2026-10-18 12:00:00'),
        )))->toThrow(ValidationException::class);

        $this->assertDatabaseCount('transactions', 1);
    });

    test('selling exactly what is held is accepted', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();
        $log = app(LogTransaction::class);
        $log->execute($editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100'));

        $log->execute($editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Sell, '1', '120'));

        $this->assertDatabaseCount('transactions', 2);
    });

    test('a withdrawal is checked like a sell', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();

        expect(fn () => app(LogTransaction::class)->execute(
            $editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Withdrawal, '0.1', '100'),
        ))->toThrow(ValidationException::class);

        $this->assertDatabaseCount('transactions', 0);
    });
});

describe('input validation', function () {
    test('bad quantities are rejected', function (string $quantity) {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();

        expect(fn () => app(LogTransaction::class)->execute(
            $editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, $quantity, '100'),
        ))->toThrow(ValidationException::class, 'Quantity must be a positive decimal with at most 8 decimal places.');

        $this->assertDatabaseCount('transactions', 0);
    })->with(['-5', '0', '0.00000000', 'abc', '1e3', '1.123456789', '', '12345678901234567']);

    test('bad prices are rejected', function (string $price) {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();

        expect(fn () => app(LogTransaction::class)->execute(
            $editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', $price),
        ))->toThrow(ValidationException::class, 'Price must be zero or a positive decimal with at most 8 decimal places.');
    })->with(['-5', 'abc', '1e3', '1.123456789']);

    test('bad fees are rejected', function (string $fee) {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();

        expect(fn () => app(LogTransaction::class)->execute(
            $editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100', $fee),
        ))->toThrow(ValidationException::class, 'Fee must be zero or a positive decimal with at most 8 decimal places.');
    })->with(['-1', 'abc', '1e3']);

    test('a zero price is accepted', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();

        app(LogTransaction::class)->execute($editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Deposit, '1', '0'));

        $this->assertDatabaseHas('transactions', ['portfolio_id' => $portfolio->id, 'price' => '0.00000000']);
    });

    test('a future date is rejected', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();

        expect(fn () => app(LogTransaction::class)->execute($editor, $portfolio, new LogTransactionData(
            $btc->id, TransactionType::Buy, '1', '100', occurredAt: CarbonImmutable::parse('2026-10-25 12:00:01'),
        )))->toThrow(ValidationException::class, 'The transaction date cannot be in the future.');

        $this->assertDatabaseCount('transactions', 0);
    });

    test('authorization is checked before input validation', function () {
        [$viewer, $portfolio] = portfolioWithRole(Role::Viewer);

        expect(fn () => app(LogTransaction::class)->execute(
            $viewer, $portfolio, new LogTransactionData(999999, TransactionType::Buy, 'abc', '100'),
        ))->toThrow(AuthorizationException::class);
    });
});

describe('idempotency', function () {
    test('the same key twice returns the same row', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();
        $log = app(LogTransaction::class);
        $data = new LogTransactionData($btc->id, TransactionType::Buy, '1', '100', idempotencyKey: 'retry-1');

        $first = $log->execute($editor, $portfolio, $data);
        $second = $log->execute($editor, $portfolio, $data);

        expect($second->id)->toBe($first->id);
        $this->assertDatabaseCount('transactions', 1);
    });

    test('the key is stored hashed with the portfolio id', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();

        $transaction = app(LogTransaction::class)->execute(
            $editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100', idempotencyKey: 'retry-1'),
        );

        expect($transaction->idempotency_key)->toBe(hash('sha256', $portfolio->id.':retry-1'));
    });

    test('the same key in a different portfolio creates a separate row', function () {
        [$firstOwner, $firstPortfolio] = portfolioWithRole(Role::Owner);
        [$secondOwner, $secondPortfolio] = portfolioWithRole(Role::Owner);
        $btc = globalAsset();
        $log = app(LogTransaction::class);

        $first = $log->execute($firstOwner, $firstPortfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100', idempotencyKey: 'same'));
        $second = $log->execute($secondOwner, $secondPortfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '100', idempotencyKey: 'same'));

        expect($second->id)->not->toBe($first->id);
        $this->assertDatabaseCount('transactions', 2);
    });
});

test('the smallest quantity keeps its precision', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $btc = globalAsset();

    $transaction = app(LogTransaction::class)->execute(
        $editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '0.00000001', '65000'),
    );

    expect(Transaction::find($transaction->id)->quantity)->toBe('0.00000001');
});
