<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. AddMember checks that the user exists and is not already a member.
 * Task 12 of http_ui_tasks.md switches this from user_id to email.
 */
class StoreMemberRequest extends FormRequest
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
            'user_id' => ['required', 'integer'],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }
}
