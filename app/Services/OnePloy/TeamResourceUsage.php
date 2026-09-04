<?php

namespace App\Services\OnePloy;

use App\Models\Application;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;

class TeamResourceUsage
{
    /**
     * Quotas with an authoritative current-state meter.
     *
     * @var list<string>
     */
    public const MEASURABLE_QUOTA_KEYS = [
        'projects.max',
        'applications.max',
        'databases.max',
        'services.max',
        'members.max',
    ];

    /**
     * @var list<class-string>
     */
    private const DATABASE_MODELS = [
        StandalonePostgresql::class,
        StandaloneRedis::class,
        StandaloneMongodb::class,
        StandaloneMysql::class,
        StandaloneMariadb::class,
        StandaloneKeydb::class,
        StandaloneDragonfly::class,
        StandaloneClickhouse::class,
    ];

    public function for(Team $team, string $quotaKey): int
    {
        return match ($quotaKey) {
            'projects.max' => Project::query()->whereBelongsTo($team)->count(),
            'applications.max' => $this->countProjectResource(Application::class, $team),
            'databases.max' => $this->databaseCount($team),
            'services.max' => $this->countProjectResource(Service::class, $team),
            'members.max' => $team->members()->count(),
            default => throw new \InvalidArgumentException(
                "Quota [{$quotaKey}] cannot be measured from authoritative resource state."
            ),
        };
    }

    public function supports(string $quotaKey): bool
    {
        return in_array($quotaKey, self::MEASURABLE_QUOTA_KEYS, true);
    }

    /**
     * @param  class-string  $model
     */
    private function countProjectResource(string $model, Team $team): int
    {
        return $model::query()
            ->whereHas('environment.project', function ($query) use ($team): void {
                $query->whereBelongsTo($team);
            })
            ->count();
    }

    private function databaseCount(Team $team): int
    {
        return collect(self::DATABASE_MODELS)
            ->sum(fn (string $model): int => $this->countProjectResource($model, $team));
    }
}
