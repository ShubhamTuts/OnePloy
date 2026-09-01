<?php

namespace App\Traits;

use App\Enums\TenantStatus;
use App\Models\Reseller;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant behaviour for Team: plan, lifecycle status, quotas and reseller attribution.
 *
 * The root team (id 0) is the platform itself and is never limited or suspended.
 */
trait IsTenant
{
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function isPlatformTeam(): bool
    {
        return $this->id === 0;
    }

    public function tenantStatus(): TenantStatus
    {
        if ($this->isPlatformTeam()) {
            return TenantStatus::Active;
        }

        return $this->tenant_status ?? TenantStatus::Active;
    }

    public function isTenantActive(): bool
    {
        if (! $this->tenantStatus()->allowsWorkloads()) {
            return false;
        }

        $reseller = $this->reseller;

        return is_null($reseller) || $reseller->isActive();
    }

    public function isSuspended(): bool
    {
        return $this->tenantStatus() === TenantStatus::Suspended;
    }

    public function isTerminated(): bool
    {
        return $this->tenantStatus() === TenantStatus::Terminated;
    }

    public function suspend(): void
    {
        $this->setTenantStatus(TenantStatus::Suspended);
    }

    public function unsuspend(): void
    {
        $this->setTenantStatus(TenantStatus::Active);
    }

    public function terminate(): void
    {
        $this->setTenantStatus(TenantStatus::Terminated);
    }

    public function setTenantStatus(TenantStatus $status): void
    {
        if ($this->isPlatformTeam()) {
            throw new \RuntimeException('The platform team cannot change tenant status.');
        }

        $this->forceFill([
            'tenant_status' => $status,
            'suspended_at' => $status === TenantStatus::Active ? null : now(),
        ])->save();
    }

    public function planSlug(): ?string
    {
        return $this->plan ?? config('tenancy.default_plan');
    }

    /**
     * @return array<string, mixed>
     */
    public function planConfig(): array
    {
        $plan = $this->planSlug();

        return blank($plan) ? [] : config('tenancy.plans.'.$plan, []);
    }

    public function assignPlan(?string $plan): void
    {
        if (filled($plan) && ! array_key_exists($plan, config('tenancy.plans', []))) {
            throw new \InvalidArgumentException("Unknown plan [{$plan}].");
        }

        $this->forceFill(['plan' => $plan])->save();
    }

    /**
     * Effective limit for a quota key: team override, else plan value, else unlimited (null).
     */
    public function quota(string $key): int|float|null
    {
        if (! in_array($key, config('tenancy.quotas'), true)) {
            throw new \InvalidArgumentException("Unknown quota key [{$key}].");
        }

        if ($this->isPlatformTeam()) {
            return null;
        }

        $override = $this->getAttribute($key);
        if (! is_null($override)) {
            return $override;
        }

        return data_get($this->planConfig(), $key);
    }

    /**
     * @return array<string, int|float|null>
     */
    public function quotas(): array
    {
        return collect(config('tenancy.quotas'))
            ->mapWithKeys(fn (string $key) => [$key => $this->quota($key)])
            ->all();
    }

    /**
     * @param  array<string, int|float|null>  $quotas
     */
    public function setQuotas(array $quotas): void
    {
        $allowed = config('tenancy.quotas');
        foreach ($quotas as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown quota key [{$key}].");
            }
        }

        $this->forceFill($quotas)->save();
    }
}
