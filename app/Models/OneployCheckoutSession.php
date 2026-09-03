<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OneployCheckoutSession extends Model
{
    protected $table = 'oneploy_checkout_sessions';

    protected $fillable = [
        'team_id',
        'user_id',
        'status',
        'provider',
        'provider_reference',
        'approval_url',
        'failure_reason',
        'provider_payload',
        'currency',
        'locale',
        'idempotency_key',
        'items',
        'attribution',
        'amount_minor',
        'coupon_code',
        'expires_at',
        'completed_at',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'attribution' => 'array',
            'provider_payload' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'amount_minor' => 'integer',
        ];
    }

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
