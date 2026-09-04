<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneployAiGatewayRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $table = 'oneploy_ai_gateway_requests';

    protected $fillable = [
        'team_id',
        'user_id',
        'idempotency_key_hash',
        'request_hash',
        'provider',
        'model',
        'upstream_model',
        'billing_period',
        'reserved_tokens',
        'status',
        'upstream_status',
        'response_payload',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'error_code',
        'completed_at',
    ];

    protected $hidden = [
        'idempotency_key_hash',
        'request_hash',
        'response_payload',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'encrypted:array',
            'upstream_status' => 'integer',
            'reserved_tokens' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
