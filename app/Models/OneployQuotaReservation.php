<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneployQuotaReservation extends Model
{
    protected $table = 'oneploy_quota_reservations';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'team_id',
        'resource_type',
        'idempotency_key',
        'quantity',
        'status',
        'resource_id',
        'expires_at',
    ];

    protected $attributes = [
        'quantity' => 1,
        'status' => self::STATUS_RESERVED,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'resource_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', self::STATUS_RESERVED)
            ->where(function (Builder $expiryQuery) use ($at): void {
                $expiryQuery
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $at);
            });
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_RESERVED
            && ($this->expires_at === null || $this->expires_at->isAfter($at));
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
