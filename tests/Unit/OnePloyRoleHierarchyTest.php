<?php

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TenantStatus;

test('role hierarchy ranks viewer below deployer below admin below owner', function () {
    expect(Role::MEMBER->rank())->toBeLessThan(Role::DEPLOYER->rank())
        ->and(Role::DEPLOYER->rank())->toBeLessThan(Role::ADMIN->rank())
        ->and(Role::ADMIN->rank())->toBeLessThan(Role::OWNER->rank());
});

test('deployer can deploy but is not an admin', function () {
    expect(Role::DEPLOYER->gte(Role::DEPLOYER))->toBeTrue()
        ->and(Role::DEPLOYER->gte(Role::ADMIN))->toBeFalse()
        ->and(Role::MEMBER->gte(Role::DEPLOYER))->toBeFalse()
        ->and(Role::ADMIN->gte(Role::DEPLOYER))->toBeTrue()
        ->and(Role::OWNER->gte(Role::DEPLOYER))->toBeTrue();
});

test('role comparisons accept string roles', function () {
    expect(Role::DEPLOYER->gt('member'))->toBeTrue()
        ->and(Role::DEPLOYER->lt('admin'))->toBeTrue();
});

test('platform role hierarchy escalates from sub-user to super admin', function () {
    expect(PlatformRole::SuperAdmin->atLeast(PlatformRole::Reseller))->toBeTrue()
        ->and(PlatformRole::Reseller->atLeast(PlatformRole::Tenant))->toBeTrue()
        ->and(PlatformRole::Tenant->atLeast(PlatformRole::Reseller))->toBeFalse()
        ->and(PlatformRole::SubUser->atLeast(PlatformRole::Tenant))->toBeFalse();
});

test('only active tenants may run workloads', function () {
    expect(TenantStatus::Active->allowsWorkloads())->toBeTrue()
        ->and(TenantStatus::Suspended->allowsWorkloads())->toBeFalse()
        ->and(TenantStatus::Terminated->allowsWorkloads())->toBeFalse();
});
