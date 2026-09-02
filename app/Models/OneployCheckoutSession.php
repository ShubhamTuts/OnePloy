<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OneployCheckoutSession extends Model
{
    protected $table = 'oneploy_checkout_sessions';

    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
        'attribution' => 'array',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'amount_minor' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->uuid ??= (string) Str::uuid();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
