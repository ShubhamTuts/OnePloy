<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OneployPlan extends Model
{
    protected $table = 'oneploy_plans';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(OneployProduct::class, 'product_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(OneployPlanVersion::class, 'plan_id');
    }

    public function publishedVersion(): ?OneployPlanVersion
    {
        return $this->versions()->where('status', 'published')->orderByDesc('version')->first();
    }
}
