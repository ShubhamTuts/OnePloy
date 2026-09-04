<?php

use App\Livewire\OnePloy\Usage;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use App\Services\OnePloy\TeamResourceUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function createStandaloneDatabasesForUsage(Environment $environment): void
{
    $common = [
        'environment_id' => $environment->id,
        'destination_type' => StandaloneDocker::class,
        'destination_id' => 999,
    ];

    StandalonePostgresql::forceCreate($common + ['name' => 'postgres', 'postgres_password' => 'secret']);
    StandaloneRedis::forceCreate($common + ['name' => 'redis']);
    StandaloneMongodb::forceCreate($common + ['name' => 'mongo', 'mongo_initdb_root_password' => 'secret']);
    StandaloneMysql::forceCreate($common + ['name' => 'mysql', 'mysql_root_password' => 'secret', 'mysql_password' => 'secret']);
    StandaloneMariadb::forceCreate($common + ['name' => 'mariadb', 'mariadb_root_password' => 'secret', 'mariadb_password' => 'secret']);
    StandaloneKeydb::forceCreate($common + ['name' => 'keydb', 'keydb_password' => 'secret']);
    StandaloneDragonfly::forceCreate($common + ['name' => 'dragonfly', 'dragonfly_password' => 'secret']);
    StandaloneClickhouse::forceCreate($common + ['name' => 'clickhouse', 'clickhouse_admin_password' => 'secret']);
}

test('resource usage counts only resources owned through the requested team project hierarchy', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    expect($project->id)->not->toBe($team->id);

    $environment = $project->environments()->firstOrFail();
    $otherEnvironment = $otherProject->environments()->firstOrFail();

    Application::factory()->create(['environment_id' => $environment->id]);
    Application::factory()->create(['environment_id' => $otherEnvironment->id]);
    Service::factory()->create(['environment_id' => $environment->id]);
    Service::factory()->create(['environment_id' => $otherEnvironment->id]);
    createStandaloneDatabasesForUsage($environment);
    createStandaloneDatabasesForUsage($otherEnvironment);

    $usage = app(TeamResourceUsage::class);

    expect($usage->for($team, 'applications.max'))->toBe(1)
        ->and($usage->for($team, 'services.max'))->toBe(1)
        ->and($usage->for($team, 'databases.max'))->toBe(8)
        ->and($usage->for($team, 'projects.max'))->toBe(1);
});

test('member usage is scoped to the team pivot', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $sharedUser = User::factory()->create();
    $team->members()->attach($sharedUser, ['role' => 'member']);
    $team->members()->attach(User::factory()->create(), ['role' => 'admin']);
    $otherTeam->members()->attach($sharedUser, ['role' => 'member']);

    expect(app(TeamResourceUsage::class)->for($team, 'members.max'))->toBe(2);
});

test('unmeasured resource keys are rejected instead of reporting zero usage', function () {
    $team = Team::factory()->create();

    expect(fn () => app(TeamResourceUsage::class)->for($team, 'cpu.max'))
        ->toThrow(InvalidArgumentException::class, 'cannot be measured');
});

test('usage component renders authoritative limits and measured team usage', function () {
    $team = Team::factory()->create();
    $team->setQuotas([
        'max_applications' => 3,
        'max_databases' => 2,
        'max_services' => 1,
    ]);
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    Application::factory()->create(['environment_id' => $project->environments()->firstOrFail()->id]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(Usage::class)
        ->assertViewHas('quotas', function ($quotas): bool {
            return $quotas->get('projects.max') === ['limit' => null, 'used' => 1]
                && $quotas->get('applications.max') === ['limit' => 3, 'used' => 1]
                && $quotas->get('databases.max') === ['limit' => 2, 'used' => 0]
                && $quotas->get('services.max') === ['limit' => 1, 'used' => 0]
                && $quotas->get('members.max') === ['limit' => null, 'used' => 1];
        })
        ->assertSee('Applications')
        ->assertSee('3');
});
