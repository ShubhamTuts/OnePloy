<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneployDomain extends Model
{
    protected $table = 'oneploy_domains';

    protected $guarded = [];

    protected $casts = [
        'privacy' => 'boolean',
        'auto_renew' => 'boolean',
        'expires_at' => 'datetime',
        'nameservers' => 'array',
        'contacts' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
