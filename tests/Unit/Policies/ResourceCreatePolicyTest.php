<?php

use App\Models\Application;
use App\Models\Team;
use App\Models\User;
use App\Policies\ResourceCreatePolicy;

function resourceCreationUser(bool $isAdmin, bool $isTenantActive = true): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn($isAdmin);

    if ($isAdmin) {
        $team = Mockery::mock(Team::class)->makePartial();
        $team->shouldReceive('isTenantActive')->andReturn($isTenantActive);
        $user->shouldReceive('currentTeam')->andReturn($team);
    }

    return $user;
}

it('allows admin to create any resource', function () {
    $user = resourceCreationUser(true);

    $policy = new ResourceCreatePolicy;
    expect($policy->createAny($user))->toBeTrue();
});

it('denies member from creating any resource', function () {
    $user = resourceCreationUser(false);

    $policy = new ResourceCreatePolicy;
    expect($policy->createAny($user))->toBeFalse();
});

it('allows admin to create a valid resource class', function () {
    $user = resourceCreationUser(true);

    $policy = new ResourceCreatePolicy;
    expect($policy->create($user, Application::class))->toBeTrue();
});

it('denies member from creating a valid resource class', function () {
    $user = resourceCreationUser(false);

    $policy = new ResourceCreatePolicy;
    expect($policy->create($user, Application::class))->toBeFalse();
});

it('denies admin from creating an invalid resource class', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new ResourceCreatePolicy;
    expect($policy->create($user, 'App\Models\NonExistent'))->toBeFalse();
});

it('allows admin to authorize all resource creation', function () {
    $user = resourceCreationUser(true);

    $policy = new ResourceCreatePolicy;
    expect($policy->authorizeAllResourceCreation($user))->toBeTrue();
});

it('denies member from authorizing all resource creation', function () {
    $user = resourceCreationUser(false);

    $policy = new ResourceCreatePolicy;
    expect($policy->authorizeAllResourceCreation($user))->toBeFalse();
});

it('denies an admin on a suspended tenant from creating resources', function () {
    $user = resourceCreationUser(true, false);

    $policy = new ResourceCreatePolicy;

    expect($policy->createAny($user))->toBeFalse()
        ->and($policy->create($user, Application::class))->toBeFalse();
});
