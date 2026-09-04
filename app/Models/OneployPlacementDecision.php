<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class OneployPlacementDecision extends Model
{
    protected $table = 'oneploy_placement_decisions';

    protected $fillable = [
        'workload_reservation_id',
        'compute_node_id',
        'inputs',
        'scores',
        'explanation',
    ];

    protected function casts(): array
    {
        return [
            'workload_reservation_id' => 'integer',
            'compute_node_id' => 'integer',
            'inputs' => 'array',
            'scores' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Persisted placement decisions are immutable.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Persisted placement decisions are immutable.');
        });
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(OneployWorkloadReservation::class, 'workload_reservation_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(OneployComputeNode::class, 'compute_node_id');
    }
}
