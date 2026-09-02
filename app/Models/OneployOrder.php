<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OneployOrder extends Model
{
    protected $table = 'oneploy_orders';

    protected $guarded = [];

    protected $casts = [
        'lines' => 'array',
        'metadata' => 'array',
        'amount_minor' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->uuid ??= (string) Str::uuid();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
