<?php

namespace App\Models;

use App\Enums\AssetType;
use App\Enums\PriceSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Asset extends Model
{
    protected $fillable = ['portfolio_id', 'type', 'symbol', 'external_id', 'name', 'currency', 'price_source'];

    protected $casts = ['type' => AssetType::class, 'price_source' => PriceSource::class];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PriceSnapshot::class);
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(PriceSnapshot::class)->latestOfMany('as_of');
    }

    public function isGlobal(): bool
    {
        return $this->portfolio_id === null;
    }
}
