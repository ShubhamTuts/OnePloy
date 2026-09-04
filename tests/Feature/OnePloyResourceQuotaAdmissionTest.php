<?php

use App\Enums\TenantStatus;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\OneployCommerceSubscription;
use App\Models\OneployPlan;
use App\Models\OneployPlanVersion;
use App\Models\OneployProduct;
use App\Models\OneployQuotaReservation;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use App\Services\OnePloy\TeamMemberAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function createResourceAdmissionSubscription(Team $team, array $entitlements): OneployCommerceSubscription
{
    $product = OneployProduct::create([
        'slug' => fake()->unique()->slug(),
        'name' => fake()->unique()->words(3, true),
        'family' => 'app_hosting',
    ]);
    $plan = OneployPlan::create([
        'product_id' => $product->id,
        'slug' => 'plan-'.fake()->unique()->word(),
        'name' => fake()->unique()->words(2, true),
    ]);
    $version = OneployPlanVersion::create([
        'plan_id' => $plan->id,
        'version' => 1,
        'status' => 'published',
        'entitlements' => $entitlements,
    ]);

    return OneployCommerceSubscription::create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'plan_version_id' => $version->id,
        'status' => 'active',
        'current_period_ends_at' => now()->addMonth(),
        'entitlement_snapshot' => $entitlements,
    ]);
}

test('finite plan quotas are enforced from every model creation path', function () {
    $team = Team::factory()->create();
    createResourceAdmissionSubscription($team, [
        'projects.max' => 1,
        'applications.max' => 1,
        'databases.max' => 1,
        'services.max' => 1,
        'members.max' => 5,
    ]);

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->firstOrFail();

    expect(fn () => Project::factory()->create(['team_id' => $team->id]))
        ->toThrow(RuntimeException::class, 'projects.max is exhausted');

    Application::factory()->create(['environment_id' => $environment->id]);
    expect(fn () => Application::factory()->create(['environment_id' => $environment->id]))
        ->toThrow(RuntimeException::class, 'applications.max is exhausted');

    Service::factory()->create(['environment_id' => $environment->id]);
    expect(fn () => Service::factory()->create(['environment_id' => $environment->id]))
        ->toThrow(RuntimeException::class, 'services.max is exhausted');

    StandaloneRedis::create([
        'name' => 'primary-cache',
        'environment_id' => $environment->id,
        'destination_id' => 1,
        'destination_type' => StandaloneDocker::class,
    ]);
    expect(fn () => StandaloneRedis::create([
        'name' => 'overflow-cache',
        'environment_id' => $environment->id,
        'destination_id' => 1,
        'destination_type' => StandaloneDocker::class,
    ]))->toThrow(RuntimeException::class, 'databases.max is exhausted');

    expect(OneployQuotaReservation::query()->where('status', OneployQuotaReservation::STATUS_CONSUMED)->count())
        ->toBe(4);
});

test('suspended tenants are denied even when their legacy quotas are unlimited', function () {
    $team = Team::factory()->create(['tenant_status' => TenantStatus::Suspended]);

    expect(fn () => Project::factory()->create(['team_id' => $team->id]))
        ->toThrow(RuntimeException::class, 'not allowed to create resources');
});

test('resource admission fails closed when tenant ownership cannot be resolved', function () {
    $team = Team::factory()->create();
    createResourceAdmissionSubscription($team, [
        'projects.max' => 1,
        'applications.max' => 1,
        'databases.max' => 1,
        'services.max' => 1,
        'members.max' => 1,
    ]);

    expect(fn () => Application::factory()->create(['environment_id' => 999999]))
        ->toThrow(RuntimeException::class, 'tenant owner');
});

test('team membership admission enforces the purchased members quota transactionally', function () {
    $team = Team::factory()->create();
    createResourceAdmissionSubscription($team, [
        'projects.max' => 1,
        'applications.max' => 1,
        'databases.max' => 1,
        'services.max' => 1,
        'members.max' => 1,
    ]);
    $firstMember = User::factory()->create();
    $overflowMember = User::factory()->create();
    $admission = app(TeamMemberAdmission::class);

    expect($admission->attach($firstMember, $team, 'member'))->toBeTrue()
        ->and($admission->attach($firstMember, $team, 'member'))->toBeFalse()
        ->and(fn () => $admission->attach($overflowMember, $team, 'member'))
        ->toThrow(RuntimeException::class, 'members.max is exhausted');

    expect($team->members()->pluck('users.id')->all())->toBe([$firstMember->id]);
});
