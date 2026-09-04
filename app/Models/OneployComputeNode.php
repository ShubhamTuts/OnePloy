<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OneployComputeNode extends Model
{
    protected $table = 'oneploy_compute_nodes';

    protected $fillable = [
        'compute_pool_id',
        'server_id',
        'labels',
        'is_draining',
        'last_seen_at',
        'last_probe_failed_at',
        'last_probe_error',
        'consecutive_probe_failures',
    ];

    protected $attributes = [
        'is_draining' => false,
        'consecutive_probe_failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'compute_pool_id' => 'integer',
            'server_id' => 'integer',
            'labels' => 'array',
            'is_draining' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_probe_failed_at' => 'datetime',
            'consecutive_probe_failures' => 'integer',
        ];
    }

    public function scopeAcceptingWorkloads(Builder $query): Builder
    {
        return $query
            ->where('is_draining', false)
            ->whereNull('last_probe_error');
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(OneployComputePool::class, 'compute_pool_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function capacitySnapshots(): HasMany
    {
        return $this->hasMany(OneployCapacitySnapshot::class, 'compute_node_id');
    }

    public function latestCapacitySnapshot(): HasOne
    {
        return $this->hasOne(OneployCapacitySnapshot::class, 'compute_node_id')
            ->ofMany([
                'captured_at' => 'max',
                'id' => 'max',
            ]);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(OneployWorkloadReservation::class, 'compute_node_id');
    }

    public function placementDecisions(): HasMany
    {
        return $this->hasMany(OneployPlacementDecision::class, 'compute_node_id');
    }
}
