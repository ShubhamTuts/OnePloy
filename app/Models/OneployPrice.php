<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneployPrice extends Model
{
    protected $table = 'oneploy_prices';

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(OneployPlanVersion::class, 'plan_version_id');
    }

    public function formatted(): string
    {
        return strtoupper($this->currency).' '.number_format($this->amount_minor / 100, 2);
    }
}
