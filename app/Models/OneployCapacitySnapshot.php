<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneployCapacitySnapshot extends Model
{
    protected $table = 'oneploy_capacity_snapshots';

    protected $fillable = [
        'compute_node_id',
        'cpu_millis_available',
        'memory_mb_available',
        'disk_gb_available',
        'gpu_available',
        'raw',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'compute_node_id' => 'integer',
            'cpu_millis_available' => 'integer',
            'memory_mb_available' => 'integer',
            'disk_gb_available' => 'integer',
            'gpu_available' => 'integer',
            'raw' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(OneployComputeNode::class, 'compute_node_id');
    }

    public function isFresh(CarbonInterface $at, int $maximumAgeSeconds): bool
    {
        return $this->captured_at->greaterThanOrEqualTo($at->copy()->subSeconds($maximumAgeSeconds));
    }

    public function hasCompleteCapacity(): bool
    {
        return $this->cpu_millis_available !== null
            && $this->memory_mb_available !== null
            && $this->disk_gb_available !== null
            && $this->gpu_available !== null;
    }

    /**
     * @return array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int}
     */
    public function availableResources(): array
    {
        return [
            'cpu_millis' => $this->cpu_millis_available,
            'memory_mb' => $this->memory_mb_available,
            'disk_gb' => $this->disk_gb_available,
            'gpu' => $this->gpu_available,
        ];
    }
}
