<?php

use App\Actions\Team\CreateTenant;
use App\Enums\TenantStatus;
use App\Models\InstanceSettings;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function resellerForTenantAction(User $owner, array $attributes = []): Reseller
{
    return Reseller::create(array_merge([
        'name' => 'Tenant Action Reseller',
        'owner_id' => $owner->id,
    ], $attributes));
}

it('creates an independent tenant for a regular user', function () {
    $owner = User::factory()->create();

    $team = CreateTenant::run($owner, 'Independent Tenant', 'Direct customer');

    expect($team->reseller_id)->toBeNull()
        ->and($team->personal_team)->toBeFalse()
        ->and($team->is_mcp_server_enabled)->toBeTrue()
        ->and($owner->fresh()->roleInTeam($team->id))->toBe('owner');
});

it('atomically attributes reseller tenants and applies the reseller default plan', function () {
    $owner = User::factory()->create();
    $reseller = resellerForTenantAction($owner, ['default_plan' => 'starter']);

    $team = CreateTenant::run($owner, 'Managed Tenant');

    expect($team->reseller_id)->toBe($reseller->id)
        ->and($team->plan)->toBe('starter')
        ->and($reseller->fresh()->tenants()->whereKey($team->id)->exists())->toBeTrue();
});

it('enforces reseller tenant capacity inside tenant creation', function () {
    $owner = User::factory()->create();
    $reseller = resellerForTenantAction($owner);
    $reseller->setQuotas(['max_tenants' => 1]);

    CreateTenant::run($owner, 'First Tenant');

    expect(fn () => CreateTenant::run($owner, 'Overflow Tenant'))
        ->toThrow(RuntimeException::class, 'tenant limit');
    expect($reseller->fresh()->tenants()->count())->toBe(1);
});

it('denies tenant creation for a suspended reseller', function () {
    $owner = User::factory()->create();
    $reseller = resellerForTenantAction($owner);
    $reseller->forceFill([
        'status' => TenantStatus::Suspended,
        'suspended_at' => now(),
    ])->save();

    expect(fn () => CreateTenant::run($owner, 'Blocked Tenant'))
        ->toThrow(AuthorizationException::class, 'Suspended resellers');
    expect($reseller->fresh()->tenants()->count())->toBe(0);
});
