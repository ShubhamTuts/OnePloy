<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OneployComputePool extends Model
{
    protected $table = 'oneploy_compute_pools';

    protected $fillable = [
        'slug',
        'name',
        'region',
        'workload_classes',
        'is_managed',
        'is_active',
    ];

    protected $attributes = [
        'is_managed' => true,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'workload_classes' => 'array',
            'is_managed' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActiveManaged(Builder $query): Builder
    {
        return $query->where('is_managed', true)->where('is_active', true);
    }

    public function supportsWorkloadClass(string $workloadClass): bool
    {
        return $this->workload_classes === null
            || in_array($workloadClass, $this->workload_classes, true);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(OneployComputeNode::class, 'compute_pool_id');
    }
}
