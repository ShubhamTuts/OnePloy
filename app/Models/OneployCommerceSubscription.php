<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneployCommerceSubscription extends Model
{
    protected $table = 'oneploy_commerce_subscriptions';

    protected $guarded = [];

    protected $casts = [
        'entitlement_snapshot' => 'array',
        'current_period_ends_at' => 'datetime',
        'grace_ends_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(OneployProduct::class, 'product_id');
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(OneployPlanVersion::class, 'plan_version_id');
    }
}
