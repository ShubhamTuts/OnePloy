<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OneployQuotaReservation extends Model
{
    protected $table = 'oneploy_quota_reservations';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
