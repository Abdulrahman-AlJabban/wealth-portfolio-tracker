<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceSnapshot extends Model
{
    protected $fillable = ['asset_id', 'price', 'currency', 'as_of', 'source', 'created_by'];

    protected $casts = ['price' => 'decimal:8', 'as_of' => 'datetime'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
