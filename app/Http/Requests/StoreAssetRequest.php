<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AssetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. CreatePrivateAsset decides which types are allowed (real estate and cash).
 */
class StoreAssetRequest extends FormRequest
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
            'type' => ['required', Rule::enum(AssetType::class)],
        ];
    }
}
