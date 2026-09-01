<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A reseller owns a set of tenants (teams), has its own aggregate quotas and
 * bills its tenants at its own price. It sits between the Super Admin and the
 * tenants it created.
 */
class Reseller extends BaseModel
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'owner_id',
        'default_plan',
        'markup_percent',
        'currency',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'status' => TenantStatus::class,
        'markup_percent' => 'float',
        'max_cpus' => 'float',
        'suspended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Reseller $reseller) {
            if (blank($reseller->slug)) {
                $reseller->slug = Str::slug($reseller->name).'-'.Str::lower(Str::random(6));
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Team::class, 'reseller_id');
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function suspend(): void
    {
        $this->status = TenantStatus::Suspended;
        $this->suspended_at = now();
        $this->save();
    }

    public function unsuspend(): void
    {
        $this->status = TenantStatus::Active;
        $this->suspended_at = null;
        $this->save();
    }

    public function hasTenantCapacity(): bool
    {
        if (is_null($this->max_tenants)) {
            return true;
        }

        return $this->tenants()->count() < $this->max_tenants;
    }

    /**
     * Aggregate limit for a quota key, or null when unlimited.
     */
    public function quota(string $key): int|float|null
    {
        if (! in_array($key, config('tenancy.quotas'), true)) {
            throw new \InvalidArgumentException("Unknown quota key [{$key}].");
        }

        return $this->getAttribute($key);
    }

    /**
     * Quotas are never mass assignable: only the platform operator may set them.
     *
     * @param  array<string, int|float|null>  $quotas
     */
    public function setQuotas(array $quotas): void
    {
        $allowed = array_merge(config('tenancy.quotas'), ['max_tenants']);
        foreach ($quotas as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown quota key [{$key}].");
            }
        }

        $this->forceFill($quotas)->save();
    }
}
