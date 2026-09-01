<?php

use App\Enums\PlatformRole;
use App\Enums\TenantStatus;
use App\Models\InstanceSettings;
use App\Models\Reseller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function oneployReseller(User $owner, array $quotas = []): Reseller
{
    $reseller = Reseller::create([
        'name' => 'Acme Hosting',
        'slug' => 'acme-hosting-'.Str::lower(Str::random(6)),
        'owner_id' => $owner->id,
    ]);

    if (filled($quotas)) {
        $reseller->setQuotas($quotas);
    }

    return $reseller;
}

test('tenant falls back to the plan quota and prefers its own override', function () {
    config()->set('tenancy.plans.starter.max_applications', 5);

    $team = Team::factory()->create();
    $team->assignPlan('starter');

    expect($team->quota('max_applications'))->toBe(5);

    $team->setQuotas(['max_applications' => 12]);

    expect($team->fresh()->quota('max_applications'))->toBe(12);
});

test('platform team has unlimited quotas', function () {
    $team = Team::factory()->create();
    $team->forceFill(['id' => 0])->save();

    expect($team->fresh()->quota('max_applications'))->toBeNull();
});

test('unknown quota keys are rejected', function () {
    $team = Team::factory()->create();

    expect(fn () => $team->quota('max_unicorns'))->toThrow(InvalidArgumentException::class);
});

test('suspending a tenant stops it from running workloads', function () {
    $team = Team::factory()->create();

    expect($team->isTenantActive())->toBeTrue();

    $team->suspend();

    expect($team->isTenantActive())->toBeFalse()
        ->and($team->tenantStatus())->toBe(TenantStatus::Suspended)
        ->and($team->suspended_at)->not->toBeNull();

    $team->unsuspend();

    expect($team->isTenantActive())->toBeTrue();
});

test('a suspended reseller suspends its tenants', function () {
    $reseller = oneployReseller(User::factory()->create());
    $team = Team::factory()->create(['reseller_id' => $reseller->id]);

    expect($team->isTenantActive())->toBeTrue();

    $reseller->suspend();

    expect($team->fresh()->isTenantActive())->toBeFalse();
});

test('reseller tenant capacity respects max_tenants', function () {
    $reseller = oneployReseller(User::factory()->create(), ['max_tenants' => 1]);

    expect($reseller->hasTenantCapacity())->toBeTrue();

    Team::factory()->create(['reseller_id' => $reseller->id]);

    expect($reseller->fresh()->hasTenantCapacity())->toBeFalse();
});

test('platform role resolves across the four levels', function () {
    $team = Team::factory()->create();

    $subUser = User::factory()->create();
    $team->members()->attach($subUser->id, ['role' => 'member']);
    expect($subUser->platformRole($team))->toBe(PlatformRole::SubUser);

    $tenantOwner = User::factory()->create();
    $team->members()->attach($tenantOwner->id, ['role' => 'owner']);
    expect($tenantOwner->platformRole($team))->toBe(PlatformRole::Tenant);

    $resellerOwner = User::factory()->create();
    oneployReseller($resellerOwner);
    expect($resellerOwner->fresh()->platformRole($team))->toBe(PlatformRole::Reseller);

    $rootTeam = Team::factory()->create();
    $rootTeam->forceFill(['id' => 0])->save();
    $superAdmin = User::factory()->create();
    $rootTeam->members()->attach($superAdmin->id, ['role' => 'owner']);
    expect($superAdmin->fresh()->platformRole($team))->toBe(PlatformRole::SuperAdmin);
});

test('a reseller can only manage its own tenants', function () {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $resellerA = oneployReseller($ownerA);
    $resellerB = oneployReseller($ownerB);

    $tenantOfA = Team::factory()->create(['reseller_id' => $resellerA->id]);
    $tenantOfB = Team::factory()->create(['reseller_id' => $resellerB->id]);
    $directTenant = Team::factory()->create();

    expect($ownerA->fresh()->canManageTenant($tenantOfA))->toBeTrue()
        ->and($ownerA->fresh()->canManageTenant($tenantOfB))->toBeFalse()
        ->and($ownerA->fresh()->canManageTenant($directTenant))->toBeFalse();
});

test('tenant owners cannot change their own plan or quotas', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner->id, ['role' => 'owner']);

    expect($owner->fresh()->can('manageTenant', $team))->toBeFalse();
});

test('a reseller cannot raise its own quotas or lift its own suspension', function () {
    $owner = User::factory()->create();
    $reseller = oneployReseller($owner);
    $owner = $owner->fresh();

    expect($owner->can('update', $reseller))->toBeTrue()
        ->and($owner->can('manageTenants', $reseller))->toBeTrue()
        ->and($owner->can('manageQuota', $reseller))->toBeFalse()
        ->and($owner->can('manageStatus', $reseller))->toBeFalse()
        ->and($owner->can('delete', $reseller))->toBeFalse();
});

test('a super admin can manage every tenant and reseller', function () {
    $rootTeam = Team::factory()->create();
    $rootTeam->forceFill(['id' => 0])->save();
    $superAdmin = User::factory()->create();
    $rootTeam->members()->attach($superAdmin->id, ['role' => 'owner']);
    $superAdmin = $superAdmin->fresh();

    $reseller = oneployReseller(User::factory()->create());
    $tenant = Team::factory()->create(['reseller_id' => $reseller->id]);

    expect($superAdmin->can('manageTenant', $tenant))->toBeTrue()
        ->and($superAdmin->can('manageQuota', $reseller))->toBeTrue()
        ->and($superAdmin->can('manageStatus', $reseller))->toBeTrue();
});

test('a suspended reseller loses tenant management rights', function () {
    $owner = User::factory()->create();
    $reseller = oneployReseller($owner);
    $reseller->suspend();

    expect($owner->fresh()->can('manageTenants', $reseller->fresh()))->toBeFalse();
});
