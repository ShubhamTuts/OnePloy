<?php

use App\Models\InstanceSettings;
use App\Models\OneployCommerceSubscription;
use App\Models\OneployPlan;
use App\Models\OneployPlanVersion;
use App\Models\OneployProduct;
use App\Models\Team;
use App\Services\OnePloy\EntitlementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function createEntitlementSubscription(
    Team $team,
    array $entitlements,
    string $family = 'app_hosting',
    string $status = 'active',
    mixed $currentPeriodEndsAt = null,
    mixed $graceEndsAt = null,
): OneployCommerceSubscription {
    $product = OneployProduct::create([
        'slug' => fake()->unique()->slug(),
        'name' => fake()->unique()->words(3, true),
        'family' => $family,
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
        'status' => $status,
        'current_period_ends_at' => $currentPeriodEndsAt,
        'grace_ends_at' => $graceEndsAt,
        'entitlement_snapshot' => $entitlements,
    ]);
}

test('an eligible app hosting snapshot is authoritative including zero false and null values', function () {
    $team = Team::factory()->create();
    $team->setQuotas(['max_applications' => 99]);
    createEntitlementSubscription($team, [
        'applications.max' => 0,
        'preview.enabled' => false,
        'storage.max' => null,
    ], currentPeriodEndsAt: now()->addMonth());

    $resolver = app(EntitlementResolver::class);

    expect($resolver->value($team, 'applications.max'))->toBe(0)
        ->and($resolver->value($team, 'preview.enabled'))->toBeFalse()
        ->and($resolver->value($team, 'storage.max'))->toBeNull()
        ->and($team->commerceSubscriptions()->count())->toBe(1)
        ->and(fn () => $resolver->value($team, 'databases.max'))
        ->toThrow(RuntimeException::class, 'does not define entitlement');
});

test('legacy team quotas are used only when there is no eligible app hosting subscription', function () {
    config()->set('tenancy.plans.starter.max_applications', 5);

    $team = Team::factory()->create();
    $team->assignPlan('starter');
    createEntitlementSubscription($team, ['applications.max' => 1], family: 'domains');
    createEntitlementSubscription(
        $team,
        ['applications.max' => 2],
        status: 'active',
        currentPeriodEndsAt: now()->subSecond(),
    );

    expect(app(EntitlementResolver::class)->limit($team, 'applications.max'))->toBe(5);
});

test('teams without an eligible subscription have deliberate compatibility behavior', function () {
    $team = Team::factory()->create();
    $resolver = app(EntitlementResolver::class);

    expect($resolver->limit($team, 'projects.max'))->toBeNull()
        ->and($resolver->limit($team, 'members.max'))->toBeNull()
        ->and(fn () => $resolver->value($team, 'unknown.entitlement'))
        ->toThrow(InvalidArgumentException::class, 'Unknown entitlement key')
        ->and(fn () => $resolver->limit($team, 'preview.enabled'))
        ->toThrow(InvalidArgumentException::class, 'not a supported numeric quota');
});

test('eligible subscription scope accepts current active and unexpired grace statuses', function () {
    $activeTeam = Team::factory()->create();
    $active = createEntitlementSubscription($activeTeam, ['applications.max' => 1], currentPeriodEndsAt: now()->addMinute());

    $graceTeam = Team::factory()->create();
    $grace = createEntitlementSubscription(
        $graceTeam,
        ['applications.max' => 2],
        status: 'past_due',
        currentPeriodEndsAt: now()->subDay(),
        graceEndsAt: now()->addMinute(),
    );

    expect(OneployCommerceSubscription::query()->eligible()->pluck('id')->all())
        ->toContain($active->id, $grace->id);
});

test('eligible subscription scope rejects expired active grace canceled and unrelated product records', function () {
    $expiredActive = createEntitlementSubscription(
        Team::factory()->create(),
        ['applications.max' => 1],
        currentPeriodEndsAt: now()->subSecond(),
    );
    $expiredGrace = createEntitlementSubscription(
        Team::factory()->create(),
        ['applications.max' => 1],
        status: 'trialing',
        graceEndsAt: now()->subSecond(),
    );
    $canceled = createEntitlementSubscription(
        Team::factory()->create(),
        ['applications.max' => 1],
        status: 'canceled',
        currentPeriodEndsAt: now()->addMonth(),
        graceEndsAt: now()->addMonth(),
    );
    $domains = createEntitlementSubscription(
        Team::factory()->create(),
        ['applications.max' => 1],
        family: 'domains',
        currentPeriodEndsAt: now()->addMonth(),
    );

    $eligibleHostingIds = OneployCommerceSubscription::query()
        ->eligible()
        ->forProductFamily('app_hosting')
        ->pluck('id')
        ->all();

    expect($eligibleHostingIds)->not->toContain($expiredActive->id, $expiredGrace->id, $canceled->id, $domains->id);
});

test('the platform team bypasses numeric entitlement limits', function () {
    $team = Team::factory()->create();
    $team->forceFill(['id' => 0])->save();
    createEntitlementSubscription($team, ['applications.max' => 0], currentPeriodEndsAt: now()->addMonth());

    expect(app(EntitlementResolver::class)->limit($team->fresh(), 'applications.max'))->toBeNull();
});
