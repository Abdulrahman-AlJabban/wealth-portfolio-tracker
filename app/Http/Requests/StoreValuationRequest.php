<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\LogTransaction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. SetAssetPrice enforces R1 and R2, a positive price and no future date.
 */
class StoreValuationRequest extends FormRequest
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
        return [
            'price' => ['required', 'string', 'regex:'.LogTransaction::DECIMAL_PATTERN],
            'as_of' => ['nullable', 'date'],
        ];
    }
}
