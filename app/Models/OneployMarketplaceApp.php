<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OneployMarketplaceApp extends Model
{
    protected $table = 'oneploy_marketplace_apps';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];
}
