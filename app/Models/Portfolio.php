<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Portfolio extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'base_currency', 'created_by'];

    public function members(): HasMany
    {
        return $this->hasMany(PortfolioMember::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function roleFor(User $user): ?Role
    {
        // PortfolioMember casts `role` to the Role enum, so this is already a Role (or null).
        return $this->members()->where('user_id', $user->id)->first()?->role;
    }

    public function usableAssets(): Builder
    {
        return Asset::query()->where(
            fn ($q) => $q->whereNull('portfolio_id')->orWhere('portfolio_id', $this->id)
        );
    }
}
