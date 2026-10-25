<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\LogTransaction;
use App\Data\LogTransactionData;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. Authorization and the domain rules (R3, R4, positive quantity) live in LogTransaction.
 * Money fields are strings: a JSON number would pass through a float and lose precision.
 */
class LogTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $decimal = 'regex:'.LogTransaction::DECIMAL_PATTERN;

        return [
            'asset_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'quantity' => ['required', 'string', $decimal],
            'price' => ['required', 'string', $decimal],
            'occurred_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function toData(): LogTransactionData
    {
        $validated = $this->validated();

        return new LogTransactionData(
            assetId: (int) $validated['asset_id'],
            type: TransactionType::from($validated['type']),
            quantity: $validated['quantity'],
            price: $validated['price'],
            occurredAt: isset($validated['occurred_at']) ? CarbonImmutable::parse($validated['occurred_at']) : null,
            note: $validated['note'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );
    }
}
