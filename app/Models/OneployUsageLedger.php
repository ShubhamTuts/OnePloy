<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneployUsageLedger extends Model
{
    public const METER_AI_GATEWAY_REQUESTS = 'ai.gateway.requests';

    protected $table = 'oneploy_usage_ledgers';

    protected $fillable = [
        'team_id',
        'meter',
        'quantity',
        'period',
        'dimensions',
    ];

    protected $attributes = [
        'quantity' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'dimensions' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
