<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OneployWorkloadReservation extends Model
{
    protected $table = 'oneploy_workload_reservations';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_RELEASED = 'released';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'compute_node_id',
        'team_id',
        'workload_class',
        'status',
        'idempotency_key',
        'requirements',
        'cpu_millis',
        'memory_mb',
        'disk_gb',
        'gpu',
        'workload_reference',
        'expires_at',
        'consumed_at',
        'released_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_RESERVED,
        'cpu_millis' => 0,
        'memory_mb' => 0,
        'disk_gb' => 0,
        'gpu' => 0,
    ];

    protected function casts(): array
    {
        return [
            'compute_node_id' => 'integer',
            'team_id' => 'integer',
            'requirements' => 'array',
            'cpu_millis' => 'integer',
            'memory_mb' => 'integer',
            'disk_gb' => 'integer',
            'gpu' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->whereIn('status', [self::STATUS_RESERVED, self::STATUS_CONSUMED])
            ->where(function (Builder $expiryQuery) use ($at): void {
                $expiryQuery
                    ->where('status', self::STATUS_CONSUMED)
                    ->orWhere(function (Builder $reservedQuery) use ($at): void {
                        $reservedQuery
                            ->where('status', self::STATUS_RESERVED)
                            ->where(function (Builder $reservationExpiryQuery) use ($at): void {
                                $reservationExpiryQuery
                                    ->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', $at);
                            });
                    });
            });
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_CONSUMED
            || ($this->status === self::STATUS_RESERVED
                && ($this->expires_at === null || $this->expires_at->isAfter($at)));
    }

    /**
     * @return array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int}
     */
    public function requestedResources(): array
    {
        return [
            'cpu_millis' => $this->cpu_millis,
            'memory_mb' => $this->memory_mb,
            'disk_gb' => $this->disk_gb,
            'gpu' => $this->gpu,
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(OneployComputeNode::class, 'compute_node_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function decision(): HasOne
    {
        return $this->hasOne(OneployPlacementDecision::class, 'workload_reservation_id');
    }
}
