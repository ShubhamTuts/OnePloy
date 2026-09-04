<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OneployPlanVersion extends Model
{
    protected $table = 'oneploy_plan_versions';

    protected $guarded = [];

    protected $casts = [
        'features' => 'array',
        'entitlements' => 'array',
        'included_usage' => 'array',
        'regions' => 'array',
        'published_at' => 'datetime',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            if ($version->getOriginal('status') === 'published') {
                $forbidden = ['entitlements', 'features', 'included_usage', 'regions'];
                foreach ($forbidden as $field) {
                    if ($version->isDirty($field)) {
                        throw new \RuntimeException('Published plan versions are immutable. Create a new version.');
                    }
                }
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(OneployPlan::class, 'plan_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(OneployPrice::class, 'plan_version_id');
    }

    public function scopeEffectiveAt(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_from')
                ->orWhere('effective_from', '<=', $at))
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $at));
    }
}
