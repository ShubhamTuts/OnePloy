<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneployDnsZone extends Model
{
    protected $table = 'oneploy_dns_zones';

    protected $fillable = [
        'team_id',
        'domain_id',
        'name',
        'status',
        'records',
        'dnssec',
    ];

    protected $attributes = [
        'status' => 'pending',
        'dnssec' => false,
    ];

    protected function casts(): array
    {
        return [
            'records' => 'array',
            'dnssec' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(OneployDomain::class, 'domain_id');
    }
}
