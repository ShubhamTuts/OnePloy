<?php

namespace App\Services\OnePloy;

use App\Exceptions\OnePloy\QuotaExceededException;
use App\Models\OneployQuotaReservation;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuotaGate
{
    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly TeamResourceUsage $usage,
    ) {}

    public function assertCanCreate(Team $team, string $quotaKey, int $quantity = 1): void
    {
        $this->assertRequestIsMeasurable($quotaKey, $quantity);

        if ($team->isPlatformTeam()) {
            return;
        }

        if (! $team->isTenantActive()) {
            throw new \RuntimeException('This tenant is not allowed to create resources.');
        }

        $limit = $this->entitlements->limit($team, $quotaKey);

        if ($limit === null) {
            return;
        }

        $used = $this->usage->for($team, $quotaKey);
        $reserved = (int) OneployQuotaReservation::query()
            ->where('team_id', $team->getKey())
            ->where('resource_type', $quotaKey)
            ->active()
            ->sum('quantity');

        if (($used + $reserved + $quantity) > $limit) {
            throw new QuotaExceededException("Plan entitlement {$quotaKey} is exhausted.");
        }
    }

    public function reserve(
        Team $team,
        string $quotaKey,
        ?string $idempotencyKey = null,
        int $quantity = 1,
    ): OneployQuotaReservation {
        $this->assertRequestIsMeasurable($quotaKey, $quantity);
        $idempotencyKey ??= (string) Str::uuid();

        return DB::transaction(function () use ($team, $quotaKey, $idempotencyKey, $quantity): OneployQuotaReservation {
            $lockedTeam = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();

            $existing = OneployQuotaReservation::query()
                ->where('team_id', $lockedTeam->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->assertIdempotentRetryMatches($existing, $quotaKey, $quantity);
            }

            $this->assertCanCreate($lockedTeam, $quotaKey, $quantity);

            return OneployQuotaReservation::create([
                'team_id' => $lockedTeam->getKey(),
                'resource_type' => $quotaKey,
                'idempotency_key' => $idempotencyKey,
                'quantity' => $quantity,
                'expires_at' => now()->addMinutes(15),
            ]);
        }, 3);
    }

    public function reserveIfLimited(
        Team $team,
        string $quotaKey,
        ?string $idempotencyKey = null,
        int $quantity = 1,
    ): ?OneployQuotaReservation {
        $this->assertRequestIsMeasurable($quotaKey, $quantity);
        $idempotencyKey ??= (string) Str::uuid();

        return DB::transaction(function () use ($team, $quotaKey, $idempotencyKey, $quantity): ?OneployQuotaReservation {
            $lockedTeam = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();

            $existing = OneployQuotaReservation::query()
                ->where('team_id', $lockedTeam->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->assertIdempotentRetryMatches($existing, $quotaKey, $quantity);
            }

            $this->assertCanCreate($lockedTeam, $quotaKey, $quantity);

            if ($this->entitlements->limit($lockedTeam, $quotaKey) === null) {
                return null;
            }

            return OneployQuotaReservation::create([
                'team_id' => $lockedTeam->getKey(),
                'resource_type' => $quotaKey,
                'idempotency_key' => $idempotencyKey,
                'quantity' => $quantity,
                'expires_at' => now()->addMinutes(15),
            ]);
        }, 3);
    }

    public function consume(OneployQuotaReservation $reservation, int $resourceId): OneployQuotaReservation
    {
        return DB::transaction(function () use ($reservation, $resourceId): OneployQuotaReservation {
            $locked = $this->lockReservation($reservation);

            if ($locked->status === OneployQuotaReservation::STATUS_CONSUMED) {
                if ($locked->resource_id !== $resourceId) {
                    throw new \RuntimeException('This reservation was consumed by a different resource.');
                }

                return $locked;
            }

            if ($locked->status === OneployQuotaReservation::STATUS_RELEASED) {
                throw new \RuntimeException('A released reservation cannot be consumed.');
            }

            if (! $locked->isActive()) {
                throw new \RuntimeException('An expired reservation cannot be consumed.');
            }

            $locked->update([
                'status' => OneployQuotaReservation::STATUS_CONSUMED,
                'resource_id' => $resourceId,
                'expires_at' => null,
            ]);

            return $locked->fresh();
        }, 3);
    }

    public function release(OneployQuotaReservation $reservation): OneployQuotaReservation
    {
        return DB::transaction(function () use ($reservation): OneployQuotaReservation {
            $locked = $this->lockReservation($reservation);

            if ($locked->status === OneployQuotaReservation::STATUS_RELEASED) {
                return $locked;
            }

            if ($locked->status === OneployQuotaReservation::STATUS_CONSUMED) {
                throw new \RuntimeException('A consumed reservation cannot be released.');
            }

            $locked->update([
                'status' => OneployQuotaReservation::STATUS_RELEASED,
                'expires_at' => null,
            ]);

            return $locked->fresh();
        }, 3);
    }

    private function assertRequestIsMeasurable(string $quotaKey, int $quantity): void
    {
        if (! $this->usage->supports($quotaKey)) {
            throw new \InvalidArgumentException(
                "Quota [{$quotaKey}] cannot be enforced until an authoritative meter exists."
            );
        }

        if ($quantity < 1) {
            throw new \InvalidArgumentException('Reservation quantity must be at least one.');
        }
    }

    private function assertIdempotentRetryMatches(
        OneployQuotaReservation $reservation,
        string $quotaKey,
        int $quantity,
    ): OneployQuotaReservation {
        if ($reservation->resource_type !== $quotaKey) {
            throw new \RuntimeException('This idempotency key was used for a different resource type.');
        }

        if ($reservation->quantity !== $quantity) {
            throw new \RuntimeException('This idempotency key was used with a different quantity.');
        }

        return $reservation;
    }

    private function lockReservation(OneployQuotaReservation $reservation): OneployQuotaReservation
    {
        return OneployQuotaReservation::query()
            ->whereKey($reservation->getKey())
            ->where('team_id', $reservation->team_id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
