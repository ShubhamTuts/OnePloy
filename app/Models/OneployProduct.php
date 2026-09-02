<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OneployProduct extends Model
{
    protected $table = 'oneploy_products';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function plans(): HasMany
    {
        return $this->hasMany(OneployPlan::class, 'product_id');
    }
}
