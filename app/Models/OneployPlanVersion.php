<?php

namespace App\Models;

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
}
