<?php

namespace App\Services\OnePloy;

use App\Models\OneployQuotaReservation;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuotaGate
{
    public function assertCanCreate(Team $team, string $quotaKey): void
    {
        if ($team->isPlatformTeam()) {
            return;
        }

        if (! $team->isTenantActive()) {
            throw new \RuntimeException('This tenant is not allowed to create resources.');
        }

        $limit = $team->quota($this->legacyQuotaKey($quotaKey));
        if ($limit === null) {
            return;
        }

        $used = $this->currentCount($team, $quotaKey);
        $reserved = OneployQuotaReservation::query()
            ->where('team_id', $team->id)
            ->where('resource_type', $quotaKey)
            ->where('status', 'reserved')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        if (($used + $reserved) >= $limit) {
            throw new \RuntimeException("Plan entitlement {$quotaKey} is exhausted.");
        }
    }

    public function reserve(Team $team, string $quotaKey, ?string $idempotencyKey = null): OneployQuotaReservation
    {
        return DB::transaction(function () use ($team, $quotaKey, $idempotencyKey) {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $this->assertCanCreate($team, $quotaKey);

            return OneployQuotaReservation::create([
                'team_id' => $team->id,
                'resource_type' => $quotaKey,
                'idempotency_key' => $idempotencyKey ?: (string) Str::uuid(),
                'status' => 'reserved',
                'expires_at' => now()->addMinutes(15),
            ]);
        });
    }

    private function legacyQuotaKey(string $quotaKey): string
    {
        return match ($quotaKey) {
            'applications.max' => 'max_applications',
            'databases.max' => 'max_databases',
            'services.max' => 'max_services',
            'cpu.max' => 'max_cpus',
            'memory_mb.max' => 'max_memory_mb',
            'disk_gb.max' => 'max_disk_gb',
            default => str_starts_with($quotaKey, 'max_') ? $quotaKey : 'max_applications',
        };
    }

    private function currentCount(Team $team, string $quotaKey): int
    {
        try {
            return match ($quotaKey) {
                'applications.max', 'max_applications' => method_exists($team, 'applications') ? (int) $team->applications()->count() : 0,
                default => 0,
            };
        } catch (\Throwable) {
            return 0;
        }
    }
}
