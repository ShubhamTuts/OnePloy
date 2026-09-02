<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OneployPaymentWebhookEvent extends Model
{
    protected $table = 'oneploy_payment_webhook_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
