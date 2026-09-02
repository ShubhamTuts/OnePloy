<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OneployPayment extends Model
{
    protected $table = 'oneploy_payments';

    protected $guarded = [];

    protected $casts = [
        'raw' => 'array',
        'amount_minor' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            $payment->uuid ??= (string) Str::uuid();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(OneployInvoice::class);
    }
}
