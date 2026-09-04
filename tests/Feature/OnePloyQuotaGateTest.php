<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\OneployCommerceSubscription;
use App\Models\OneployPlan;
use App\Models\OneployPlanVersion;
use App\Models\OneployProduct;
use App\Models\OneployQuotaReservation;
use App\Models\Project;
use App\Models\Team;
use App\Services\OnePloy\QuotaGate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function createQuotaSubscription(Team $team, array $entitlements): OneployCommerceSubscription
{
    $entitlements = array_merge([
        'projects.max' => null,
        'applications.max' => null,
        'databases.max' => null,
        'services.max' => null,
        'members.max' => null,
    ], $entitlements);

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

test('quota admission includes current resource usage and active reservation quantities', function () {
    $team = Team::factory()->create();
    createQuotaSubscription($team, ['applications.max' => 4]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    Application::factory()->create(['environment_id' => $project->environments()->firstOrFail()->id]);

    $gate = app(QuotaGate::class);
    $gate->reserve($team, 'applications.max', 'batch-one', 2);

    expect(fn () => $gate->reserve($team, 'applications.max', 'batch-two', 2))
        ->toThrow(RuntimeException::class, 'exhausted');
});

test('reservation retries return the same row before another capacity check', function () {
    $team = Team::factory()->create();
    createQuotaSubscription($team, ['applications.max' => 2]);
    $gate = app(QuotaGate::class);

    $reservation = $gate->reserve($team, 'applications.max', 'create-apps', 2);
    $retried = $gate->reserve($team, 'applications.max', 'create-apps', 2);

    expect($retried->is($reservation))->toBeTrue()
        ->and(OneployQuotaReservation::query()->count())->toBe(1);
});

test('reservation retries reject a mismatched resource type or quantity', function () {
    $team = Team::factory()->create();
    createQuotaSubscription($team, [
        'applications.max' => 5,
        'services.max' => 5,
    ]);
    $gate = app(QuotaGate::class);
    $gate->reserve($team, 'applications.max', 'same-operation', 2);

    expect(fn () => $gate->reserve($team, 'services.max', 'same-operation', 2))
        ->toThrow(RuntimeException::class, 'different resource type')
        ->and(fn () => $gate->reserve($team, 'applications.max', 'same-operation', 1))
        ->toThrow(RuntimeException::class, 'different quantity');
});

test('expired and released reservations stop consuming quota', function () {
    $team = Team::factory()->create();
    createQuotaSubscription($team, ['applications.max' => 1]);
    OneployQuotaReservation::create([
        'team_id' => $team->id,
        'resource_type' => 'applications.max',
        'idempotency_key' => 'expired',
        'quantity' => 1,
        'expires_at' => now()->subSecond(),
    ]);

    $gate = app(QuotaGate::class);
    $reservation = $gate->reserve($team, 'applications.max', 'available', 1);
    $gate->release($reservation);

    expect($gate->reserve($team, 'applications.max', 'available-again', 1))->toBeInstanceOf(OneployQuotaReservation::class);
});

test('consume and release transitions are locked and idempotent', function () {
    $team = Team::factory()->create();
    createQuotaSubscription($team, ['applications.max' => 2]);
    $gate = app(QuotaGate::class);

    $consumable = $gate->reserve($team, 'applications.max', 'consume-me');
    $consumed = $gate->consume($consumable, 123);

    expect($consumed->status)->toBe(OneployQuotaReservation::STATUS_CONSUMED)
        ->and($consumed->resource_id)->toBe(123)
        ->and($gate->consume($consumed, 123)->status)->toBe(OneployQuotaReservation::STATUS_CONSUMED)
        ->and(fn () => $gate->consume($consumed, 456))
        ->toThrow(RuntimeException::class, 'different resource');

    $releasable = $gate->reserve($team, 'applications.max', 'release-me');
    expect($gate->release($releasable)->status)->toBe(OneployQuotaReservation::STATUS_RELEASED)
        ->and($gate->release($releasable)->status)->toBe(OneployQuotaReservation::STATUS_RELEASED)
        ->and(fn () => $gate->consume($releasable, 789))
        ->toThrow(RuntimeException::class, 'released');
});

test('the same idempotency key can be used independently by different teams', function () {
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();
    createQuotaSubscription($firstTeam, ['applications.max' => 1]);
    createQuotaSubscription($secondTeam, ['applications.max' => 1]);

    $gate = app(QuotaGate::class);
    $gate->reserve($firstTeam, 'applications.max', 'shared-key');
    $gate->reserve($secondTeam, 'applications.max', 'shared-key');

    expect(OneployQuotaReservation::query()->where('idempotency_key', 'shared-key')->count())->toBe(2);
});

test('quota gate rejects unsupported or unmeasured quota keys', function (string $key) {
    $team = Team::factory()->create();

    expect(fn () => app(QuotaGate::class)->assertCanCreate($team, $key))
        ->toThrow(InvalidArgumentException::class);
})->with(['cpu.max', 'memory_mb.max', 'disk_gb.max', 'containers.max', 'unknown.max']);
