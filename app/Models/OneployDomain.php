<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class OneployDomain extends Model
{
    protected $table = 'oneploy_domains';

    protected $fillable = [
        'uuid',
        'team_id',
        'checkout_session_id',
        'name',
        'status',
        'registrar',
        'provider_reference',
        'currency',
        'amount_minor',
        'years',
        'provisioning_attempts',
        'last_error',
        'provisioned_at',
        'privacy',
        'auto_renew',
        'expires_at',
        'nameservers',
        'contacts',
        'contact_payload',
    ];

    protected $hidden = [
        'contacts',
        'contact_payload',
    ];

    protected $attributes = [
        'status' => 'pending',
        'privacy' => false,
        'auto_renew' => true,
        'years' => 1,
        'provisioning_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'privacy' => 'boolean',
            'auto_renew' => 'boolean',
            'expires_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'amount_minor' => 'integer',
            'years' => 'integer',
            'provisioning_attempts' => 'integer',
            'nameservers' => 'array',
            'contacts' => 'array',
            'contact_payload' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $domain) {
            $domain->uuid ??= (string) Str::uuid();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(OneployCheckoutSession::class, 'checkout_session_id');
    }

    public function dnsZone(): HasOne
    {
        return $this->hasOne(OneployDnsZone::class, 'domain_id');
    }
}
