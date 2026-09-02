<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OneployInvoice extends Model
{
    protected $table = 'oneploy_invoices';

    protected $guarded = [];

    protected $casts = [
        'lines' => 'array',
        'amount_minor' => 'integer',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invoice) {
            $invoice->uuid ??= (string) Str::uuid();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OneployOrder::class);
    }
}
