<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\TransactionType;
use Carbon\CarbonImmutable;

/**
 * Typed input for LogTransaction, shared by the web form, the agent and tests.
 * Money and quantities are decimal strings so they never pass through a float.
 * Validation happens in the Action, not here.
 */
final readonly class LogTransactionData
{
    public function __construct(
        public int $assetId,
        public TransactionType $type,
        public string $quantity,
        public string $price,
        public string $fee = '0',
        public ?CarbonImmutable $occurredAt = null,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {}
}
