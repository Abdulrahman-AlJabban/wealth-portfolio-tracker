<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\LogTransactionData;
use App\Enums\TransactionSource;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PositionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for new ledger rows. The web form, the agent and tests all call this,
 * so it authorizes and validates by itself instead of trusting its caller.
 */
final class LogTransaction
{
    /**
     * Up to 16 integer digits and 8 decimals, matching DECIMAL(24,8). No sign, no exponent.
     * Public so every Action that accepts money uses the same definition.
     */
    public const DECIMAL_PATTERN = '/^\d{1,16}(\.\d{1,8})?$/';

    public function __construct(private PositionService $positions) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(
        User $user,
        Portfolio $portfolio,
        LogTransactionData $data,
        TransactionSource $source = TransactionSource::User,
    ): Transaction {
        Gate::forUser($user)->authorize('write', $portfolio);

        $this->validate($data);

        return DB::transaction(function () use ($user, $portfolio, $data, $source) {
            // Serializes writers on this portfolio, so two sells cannot both pass the R4 check,
            // and two requests with the same idempotency key cannot both miss the lookup below.
            Portfolio::query()->whereKey($portfolio->id)->lockForUpdate()->firstOrFail();

            // Hashed with the portfolio id, so the same client key in two portfolios never collides.
            $idempotencyKey = $data->idempotencyKey === null
                ? null
                : hash('sha256', $portfolio->id.':'.$data->idempotencyKey);

            if ($idempotencyKey !== null) {
                $existing = Transaction::query()
                    ->where('portfolio_id', $portfolio->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    $this->assertSameRequest($existing, $data);

                    return $existing;
                }
            }

            // R3: the same message whether the asset does not exist or belongs to another portfolio,
            // so a user cannot probe for other portfolios' private assets.
            $asset = $portfolio->usableAssets()->whereKey($data->assetId)->first();

            if ($asset === null) {
                throw ValidationException::withMessages([
                    'asset_id' => 'Asset not available in this portfolio.',
                ]);
            }

            if ($asset->currency !== $portfolio->base_currency) {
                throw ValidationException::withMessages([
                    'asset_id' => "Only {$portfolio->base_currency} assets can be traded in this single-currency prototype.",
                ]);
            }

            $transaction = Transaction::create([
                'portfolio_id' => $portfolio->id,
                'asset_id' => $asset->id,
                'type' => $data->type,
                'quantity' => $data->quantity,
                'price' => $data->price,
                'fee' => $data->fee,
                'occurred_at' => $data->occurredAt ?? now(),
                'created_by' => $user->id,
                'source' => $source,
                'idempotency_key' => $idempotencyKey,
                'note' => $data->note,
            ]);

            // R4: checked after the insert so the new row is part of the replay. A failure here
            // throws out of DB::transaction, which rolls the insert back.
            if ($data->type->isOutflow()) {
                $this->positions->assertNeverNegative($portfolio->id, $asset->id);
            }

            return $transaction;
        });
    }

    /**
     * Checked here and not only in a Form Request, because the agent never goes through one.
     *
     * @throws ValidationException
     */
    private function validate(LogTransactionData $data): void
    {
        $errors = [];

        if (! $this->isDecimal($data->quantity) || bccomp($data->quantity, '0', 8) <= 0) {
            $errors['quantity'] = 'Quantity must be a positive decimal with at most 8 decimal places.';
        }

        // The pattern has no minus sign, so a matching price is never below zero.
        if (! $this->isDecimal($data->price)) {
            $errors['price'] = 'Price must be zero or a positive decimal with at most 8 decimal places.';
        }

        if (! $this->isDecimal($data->fee)) {
            $errors['fee'] = 'Fee must be zero or a positive decimal with at most 8 decimal places.';
        }

        if ($data->occurredAt?->isFuture()) {
            $errors['occurred_at'] = 'The transaction date cannot be in the future.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A reused idempotency key must describe the same trade. Otherwise the caller would be told
     * their new transaction was logged while they actually got back a different, older one.
     * occurred_at is not compared because it defaults to "now", which differs on every retry.
     *
     * @throws ValidationException
     */
    private function assertSameRequest(Transaction $existing, LogTransactionData $data): void
    {
        $isSameRequest = $existing->asset_id === $data->assetId
            && $existing->type === $data->type
            && bccomp($existing->quantity, $data->quantity, 8) === 0
            && bccomp($existing->price, $data->price, 8) === 0
            && bccomp($existing->fee, $data->fee, 8) === 0;

        if (! $isSameRequest) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This idempotency key was already used for a different transaction.',
            ]);
        }
    }

    private function isDecimal(string $value): bool
    {
        return preg_match(self::DECIMAL_PATTERN, $value) === 1;
    }
}
