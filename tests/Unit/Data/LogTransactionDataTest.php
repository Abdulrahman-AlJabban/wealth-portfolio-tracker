<?php

declare(strict_types=1);

use App\Data\LogTransactionData;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;

test('optional fields default to zero fee and nulls', function () {
    $data = new LogTransactionData(1, TransactionType::Buy, '0.5', '60000');

    expect($data->assetId)->toBe(1)
        ->and($data->type)->toBe(TransactionType::Buy)
        ->and($data->quantity)->toBe('0.5')
        ->and($data->price)->toBe('60000')
        ->and($data->fee)->toBe('0')
        ->and($data->occurredAt)->toBeNull()
        ->and($data->note)->toBeNull()
        ->and($data->idempotencyKey)->toBeNull();
});

test('decimal strings are kept exactly as given', function () {
    $occurredAt = CarbonImmutable::parse('2026-10-01 12:00:00');

    $data = new LogTransactionData(
        assetId: 7,
        type: TransactionType::Sell,
        quantity: '0.00000001',
        price: '65000.12345678',
        fee: '1.50',
        occurredAt: $occurredAt,
        note: 'Rebalance',
        idempotencyKey: 'abc',
    );

    expect($data->quantity)->toBe('0.00000001')
        ->and($data->price)->toBe('65000.12345678')
        ->and($data->fee)->toBe('1.50')
        ->and($data->occurredAt)->toBe($occurredAt)
        ->and($data->note)->toBe('Rebalance')
        ->and($data->idempotencyKey)->toBe('abc');
});

test('properties cannot be changed after construction', function () {
    $data = new LogTransactionData(1, TransactionType::Buy, '1', '100');

    expect(fn () => $data->quantity = '2')->toThrow(Error::class);
});
