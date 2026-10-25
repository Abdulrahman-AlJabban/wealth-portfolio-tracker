<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Role;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a portfolio and makes its creator the first Owner, in one transaction,
 * so a portfolio never exists without an Owner. Any signed-in user may call it,
 * so there is no Gate check.
 */
final class CreatePortfolio
{
    /**
     * @throws ValidationException
     */
    public function execute(User $user, string $name, string $baseCurrency = 'USD'): Portfolio
    {
        $name = trim($name);

        $this->validate($name, $baseCurrency);

        return DB::transaction(function () use ($user, $name, $baseCurrency) {
            $portfolio = Portfolio::create([
                'name' => $name,
                'base_currency' => $baseCurrency,
                'created_by' => $user->id,
            ]);

            $portfolio->members()->create([
                'user_id' => $user->id,
                'role' => Role::Owner,
            ]);

            return $portfolio;
        });
    }

    /**
     * @throws ValidationException
     */
    private function validate(string $name, string $baseCurrency): void
    {
        $errors = [];

        // Str::length counts characters, not bytes, so a 100-character Arabic name is allowed.
        if (Str::length($name) < 1 || Str::length($name) > 100) {
            $errors['name'] = 'The name must be between 1 and 100 characters.';
        }

        if (preg_match('/^[A-Z]{3}$/', $baseCurrency) !== 1) {
            $errors['base_currency'] = 'The base currency must be a 3-letter uppercase code, such as USD.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
