<?php

namespace App\Observers\OnePloy;

use App\Models\Application;
use App\Models\Environment;
use App\Models\OneployQuotaReservation;
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
use App\Services\OnePloy\QuotaGate;
use Illuminate\Database\Eloquent\Model;

class ResourceQuotaObserver
{
    private const RESERVATION_RELATION = '__oneployQuotaReservation';

    /**
     * @var array<class-string<Model>, string>
     */
    public const MODEL_QUOTA_KEYS = [
        Project::class => 'projects.max',
        Application::class => 'applications.max',
        Service::class => 'services.max',
        StandalonePostgresql::class => 'databases.max',
        StandaloneRedis::class => 'databases.max',
        StandaloneMongodb::class => 'databases.max',
        StandaloneMysql::class => 'databases.max',
        StandaloneMariadb::class => 'databases.max',
        StandaloneKeydb::class => 'databases.max',
        StandaloneDragonfly::class => 'databases.max',
        StandaloneClickhouse::class => 'databases.max',
    ];

    public static function register(): void
    {
        foreach (array_keys(self::MODEL_QUOTA_KEYS) as $model) {
            $model::observe(self::class);
        }
    }

    public function creating(Model $resource): void
    {
        $resourceClass = $resource::class;
        $quotaKey = self::MODEL_QUOTA_KEYS[$resourceClass]
            ?? throw new \LogicException("No quota is mapped for [{$resourceClass}].");
        $team = $this->resolveTeam($resource);
        $reservation = app(QuotaGate::class)->reserveIfLimited(
            team: $team,
            quotaKey: $quotaKey,
            idempotencyKey: implode(':', [$resource::class, $resource->getAttribute('uuid')]),
        );

        if ($reservation) {
            $resource->setRelation(self::RESERVATION_RELATION, $reservation);
        }
    }

    public function created(Model $resource): void
    {
        if (! $resource->relationLoaded(self::RESERVATION_RELATION)) {
            return;
        }

        $reservation = $resource->getRelation(self::RESERVATION_RELATION);

        if ($reservation instanceof OneployQuotaReservation) {
            app(QuotaGate::class)->consume($reservation, (int) $resource->getKey());
            $resource->unsetRelation(self::RESERVATION_RELATION);
        }
    }

    public function creationFailed(Model $resource): void
    {
        if (! $resource->relationLoaded(self::RESERVATION_RELATION)) {
            return;
        }

        $reservation = $resource->getRelation(self::RESERVATION_RELATION);

        if ($reservation instanceof OneployQuotaReservation) {
            app(QuotaGate::class)->release($reservation);
            $resource->unsetRelation(self::RESERVATION_RELATION);
        }
    }

    private function resolveTeam(Model $resource): Team
    {
        if ($resource instanceof Project) {
            $team = Team::query()->find($resource->getAttribute('team_id'));
        } else {
            $environment = Environment::query()
                ->with('project.team')
                ->find($resource->getAttribute('environment_id'));
            $team = $environment?->project?->team;
        }

        if (! $team instanceof Team) {
            throw new \RuntimeException('The tenant owner for this resource could not be resolved.');
        }

        return $team;
    }
}
