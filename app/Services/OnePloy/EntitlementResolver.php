<?php

namespace App\Services\OnePloy;

use App\Models\OneployCommerceSubscription;
use App\Models\Team;

class EntitlementResolver
{
    /**
     * Numeric entitlement keys understood by the compatibility resolver.
     *
     * @var list<string>
     */
    public const QUOTA_KEYS = [
        'projects.max',
        'applications.max',
        'databases.max',
        'services.max',
        'members.max',
        'containers.max',
        'cpu.max',
        'memory_mb.max',
        'disk_gb.max',
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_QUOTA_KEYS = [
        'applications.max' => 'max_applications',
        'databases.max' => 'max_databases',
        'services.max' => 'max_services',
        'containers.max' => 'max_containers',
        'cpu.max' => 'max_cpus',
        'memory_mb.max' => 'max_memory_mb',
        'disk_gb.max' => 'max_disk_gb',
    ];

    /**
     * @var array<int, OneployCommerceSubscription|null>
     */
    private array $subscriptions = [];

    public function value(Team $team, string $key): mixed
    {
        $subscription = $this->appHostingSubscription($team);

        if ($subscription) {
            $snapshot = $subscription->entitlement_snapshot ?? [];

            if (array_key_exists($key, $snapshot)) {
                return $snapshot[$key];
            }

            throw new \RuntimeException(
                "The active app hosting subscription does not define entitlement [{$key}]."
            );
        }

        if (array_key_exists($key, self::LEGACY_QUOTA_KEYS)) {
            return $team->quota(self::LEGACY_QUOTA_KEYS[$key]);
        }

        if (in_array($key, ['projects.max', 'members.max'], true)) {
            return null;
        }

        throw new \InvalidArgumentException("Unknown entitlement key [{$key}].");
    }

    public function limit(Team $team, string $key): int|float|null
    {
        if (! in_array($key, self::QUOTA_KEYS, true)) {
            throw new \InvalidArgumentException("Entitlement [{$key}] is not a supported numeric quota.");
        }

        if ($team->isPlatformTeam()) {
            return null;
        }

        $value = $this->value($team, $key);

        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new \RuntimeException("Entitlement [{$key}] must be a numeric limit or null.");
        }

        if ($value < 0) {
            throw new \RuntimeException("Entitlement [{$key}] cannot be negative.");
        }

        return $value;
    }

    public function appHostingSubscription(Team $team): ?OneployCommerceSubscription
    {
        $teamId = (int) $team->getKey();

        if (! array_key_exists($teamId, $this->subscriptions)) {
            $this->subscriptions[$teamId] = OneployCommerceSubscription::query()
                ->forTeam($team)
                ->forProductFamily('app_hosting')
                ->eligible()
                ->latest('id')
                ->first();
        }

        return $this->subscriptions[$teamId];
    }
}
