<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. CreatePortfolio re-checks the name length after trimming and the currency format.
 */
class StorePortfolioRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'base_currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
        ];
    }
}
