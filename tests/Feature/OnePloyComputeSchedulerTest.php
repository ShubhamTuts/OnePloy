<?php

use App\Models\InstanceSettings;
use App\Models\OneployCapacitySnapshot;
use App\Models\OneployComputeNode;
use App\Models\OneployComputePool;
use App\Models\OneployPlacementDecision;
use App\Models\OneployWorkloadReservation;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\OnePloy\ManagedComputeScheduler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('oneploy.scheduler.capacity_snapshot_max_age_seconds', 120);
    config()->set('oneploy.scheduler.reservation_ttl_seconds', 300);
});

/**
 * @param  array<string, mixed>  $poolOverrides
 * @param  array<string, mixed>  $nodeOverrides
 * @param  array<string, mixed>  $capacityOverrides
 */
function createManagedComputeNode(
    array $poolOverrides = [],
    array $nodeOverrides = [],
    array $capacityOverrides = [],
): OneployComputeNode {
    $privateKey = PrivateKey::query()->first();

    if (! $privateKey) {
        $infrastructureTeam = Team::factory()->create();
        $privateKey = PrivateKey::factory()->create(['team_id' => $infrastructureTeam->id]);
    } else {
        $infrastructureTeam = Team::query()->findOrFail($privateKey->team_id);
    }

    $server = Server::factory()->create([
        'team_id' => $infrastructureTeam->id,
        'private_key_id' => $privateKey->id,
    ]);
    $pool = OneployComputePool::create(array_merge([
        'slug' => fake()->unique()->slug(),
        'name' => fake()->unique()->words(2, true),
        'region' => 'in-bom-1',
        'workload_classes' => ['application', 'service'],
        'is_managed' => true,
        'is_active' => true,
    ], $poolOverrides));
    $node = OneployComputeNode::create(array_merge([
        'compute_pool_id' => $pool->id,
        'server_id' => $server->id,
        'labels' => ['tier' => 'general'],
        'is_draining' => false,
    ], $nodeOverrides));

    OneployCapacitySnapshot::create(array_merge([
        'compute_node_id' => $node->id,
        'cpu_millis_available' => 8000,
        'memory_mb_available' => 16384,
        'disk_gb_available' => 200,
        'gpu_available' => 0,
        'captured_at' => now(),
    ], $capacityOverrides));

    return $node;
}

test('scheduler deterministically chooses the lowest-load node and records its scoring evidence', function () {
    $team = Team::factory()->create();
    $busyNode = createManagedComputeNode();
    $emptyNode = createManagedComputeNode();
    OneployWorkloadReservation::create([
        'compute_node_id' => $busyNode->id,
        'team_id' => $team->id,
        'workload_class' => 'application',
        'status' => OneployWorkloadReservation::STATUS_RESERVED,
        'idempotency_key' => 'existing-load',
        'cpu_millis' => 4000,
        'memory_mb' => 4096,
        'disk_gb' => 20,
        'gpu' => 0,
        'requirements' => [],
        'expires_at' => now()->addMinutes(5),
    ]);

    $reservation = app(ManagedComputeScheduler::class)->reserve(
        $team,
        'application',
        1000,
        1024,
        10,
        0,
        'deploy-app-1',
        'in-bom-1',
    );

    expect($reservation->compute_node_id)->toBe($emptyNode->id)
        ->and($reservation->decision)->toBeInstanceOf(OneployPlacementDecision::class)
        ->and($reservation->decision->scores)->toHaveKeys([
            'load_basis_points',
            'headroom_basis_points',
            'available_before',
            'headroom_after',
        ])
        ->and($reservation->decision->inputs)->not->toHaveKey('compute_node_id')
        ->and(OneployPlacementDecision::query()->count())->toBe(1);
});

test('scheduler filters workload class region inactive unmanaged and draining nodes', function () {
    $team = Team::factory()->create();
    createManagedComputeNode(['workload_classes' => ['database']]);
    createManagedComputeNode(['region' => 'us-east-1']);
    createManagedComputeNode(['is_active' => false]);
    createManagedComputeNode(['is_managed' => false]);
    createManagedComputeNode([], ['is_draining' => true]);
    $eligibleNode = createManagedComputeNode();

    $reservation = app(ManagedComputeScheduler::class)->reserve(
        $team,
        'application',
        500,
        512,
        5,
        0,
        'filtered-deploy',
        'in-bom-1',
    );

    expect($reservation->compute_node_id)->toBe($eligibleNode->id);
});

test('scheduler rejects stale telemetry separately from insufficient capacity', function () {
    $team = Team::factory()->create();
    createManagedComputeNode(capacityOverrides: ['captured_at' => now()->subMinutes(10)]);

    expect(fn () => app(ManagedComputeScheduler::class)->reserve(
        $team,
        'application',
        500,
        512,
        5,
        0,
        'stale-capacity',
        'in-bom-1',
    ))->toThrow(RuntimeException::class, 'fresh capacity snapshot');

    OneployCapacitySnapshot::query()->update(['captured_at' => now()]);

    expect(fn () => app(ManagedComputeScheduler::class)->reserve(
        $team,
        'application',
        9000,
        512,
        5,
        0,
        'insufficient-capacity',
        'in-bom-1',
    ))->toThrow(RuntimeException::class, 'Insufficient compute capacity');
});

test('scheduler subtracts active reservations while ignoring expired and released reservations', function () {
    $team = Team::factory()->create();
    $firstNode = createManagedComputeNode(capacityOverrides: [
        'cpu_millis_available' => 2000,
        'memory_mb_available' => 2048,
        'disk_gb_available' => 20,
    ]);
    $secondNode = createManagedComputeNode(capacityOverrides: [
        'cpu_millis_available' => 2000,
        'memory_mb_available' => 2048,
        'disk_gb_available' => 20,
    ]);

    foreach ([
        ['node' => $firstNode, 'key' => 'active', 'status' => 'reserved', 'expires_at' => now()->addMinute()],
        ['node' => $secondNode, 'key' => 'expired', 'status' => 'reserved', 'expires_at' => now()->subMinute()],
        ['node' => $secondNode, 'key' => 'released', 'status' => 'released', 'expires_at' => null],
    ] as $claim) {
        OneployWorkloadReservation::create([
            'compute_node_id' => $claim['node']->id,
            'team_id' => $team->id,
            'workload_class' => 'application',
            'status' => $claim['status'],
            'idempotency_key' => $claim['key'],
            'cpu_millis' => 1500,
            'memory_mb' => 1024,
            'disk_gb' => 5,
            'gpu' => 0,
            'requirements' => [],
            'expires_at' => $claim['expires_at'],
        ]);
    }

    $reservation = app(ManagedComputeScheduler::class)->reserve(
        $team,
        'application',
        1000,
        512,
        5,
        0,
        'subtract-active-only',
        'in-bom-1',
    );

    expect($reservation->compute_node_id)->toBe($secondNode->id);
});

test('consumed workload reservations continue to hold capacity until released', function () {
    $team = Team::factory()->create();
    $occupiedNode = createManagedComputeNode(capacityOverrides: [
        'cpu_millis_available' => 2000,
        'memory_mb_available' => 2048,
        'disk_gb_available' => 20,
    ]);
    $emptyNode = createManagedComputeNode(capacityOverrides: [
        'cpu_millis_available' => 2000,
        'memory_mb_available' => 2048,
        'disk_gb_available' => 20,
    ]);
    OneployWorkloadReservation::create([
        'compute_node_id' => $occupiedNode->id,
        'team_id' => $team->id,
        'workload_class' => 'application',
        'status' => OneployWorkloadReservation::STATUS_CONSUMED,
        'idempotency_key' => 'running-workload',
        'cpu_millis' => 1500,
        'memory_mb' => 1024,
        'disk_gb' => 5,
        'gpu' => 0,
        'requirements' => [],
        'workload_reference' => 'application:running',
        'consumed_at' => now(),
    ]);

    $reservation = app(ManagedComputeScheduler::class)->reserve(
        $team,
        'application',
        1000,
        512,
        5,
        0,
        'after-running-workload',
        'in-bom-1',
    );

    expect($reservation->compute_node_id)->toBe($emptyNode->id);
});

test('reservation retries are team-scoped and reject changed placement inputs', function () {
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();
    createManagedComputeNode();
    $scheduler = app(ManagedComputeScheduler::class);

    $first = $scheduler->reserve($firstTeam, 'application', 1000, 1024, 10, 0, 'same-key', 'in-bom-1');
    $retried = $scheduler->reserve($firstTeam, 'application', 1000, 1024, 10, 0, 'same-key', 'in-bom-1');
    $second = $scheduler->reserve($secondTeam, 'application', 1000, 1024, 10, 0, 'same-key', 'in-bom-1');

    expect($retried->is($first))->toBeTrue()
        ->and($second->isNot($first))->toBeTrue()
        ->and(OneployWorkloadReservation::query()->where('idempotency_key', 'same-key')->count())->toBe(2)
        ->and(OneployPlacementDecision::query()->count())->toBe(2)
        ->and(fn () => $scheduler->reserve(
            $firstTeam,
            'service',
            1000,
            1024,
            10,
            0,
            'same-key',
            'in-bom-1',
        ))->toThrow(RuntimeException::class, 'different placement inputs');
});

test('consume release and expiry lifecycle is idempotent and team safe', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    createManagedComputeNode();
    $scheduler = app(ManagedComputeScheduler::class);
    $reservation = $scheduler->reserve($team, 'application', 1000, 1024, 10, 0, 'lifecycle', 'in-bom-1');

    expect(fn () => $scheduler->consume($otherTeam, $reservation, 'app-public-reference'))
        ->toThrow(RuntimeException::class, 'does not belong to this team');

    $consumed = $scheduler->consume($team, $reservation, 'app-public-reference');

    expect($consumed->status)->toBe(OneployWorkloadReservation::STATUS_CONSUMED)
        ->and($consumed->workload_reference)->toBe('app-public-reference')
        ->and($scheduler->consume($team, $consumed, 'app-public-reference')->status)
        ->toBe(OneployWorkloadReservation::STATUS_CONSUMED)
        ->and(fn () => $scheduler->consume($team, $consumed, 'different-reference'))
        ->toThrow(RuntimeException::class, 'different workload');

    $released = $scheduler->release($team, $consumed);

    expect($released->status)->toBe(OneployWorkloadReservation::STATUS_RELEASED)
        ->and($scheduler->release($team, $released)->status)
        ->toBe(OneployWorkloadReservation::STATUS_RELEASED);

    $expiring = $scheduler->reserve($team, 'application', 1000, 1024, 10, 0, 'expires', 'in-bom-1');
    $expiring->forceFill(['expires_at' => now()->subSecond()])->save();

    expect($scheduler->expire(now()))->toBe(1)
        ->and($expiring->fresh()->status)->toBe(OneployWorkloadReservation::STATUS_EXPIRED)
        ->and(fn () => $scheduler->consume($team, $expiring, 'too-late'))
        ->toThrow(RuntimeException::class, 'expired');
});

test('SQLite cannot emulate row locks but database constraints prevent duplicate reservations and placement decisions', function () {
    $team = Team::factory()->create();
    createManagedComputeNode();
    $scheduler = app(ManagedComputeScheduler::class);
    $reservation = $scheduler->reserve($team, 'application', 1000, 1024, 10, 0, 'concurrent-key', 'in-bom-1');

    expect(fn () => OneployWorkloadReservation::create([
        'compute_node_id' => $reservation->compute_node_id,
        'team_id' => $team->id,
        'workload_class' => 'application',
        'idempotency_key' => 'concurrent-key',
        'cpu_millis' => 1000,
        'memory_mb' => 1024,
        'disk_gb' => 10,
        'gpu' => 0,
        'requirements' => [],
    ]))->toThrow(QueryException::class)
        ->and(fn () => OneployPlacementDecision::create([
            'workload_reservation_id' => $reservation->id,
            'compute_node_id' => $reservation->compute_node_id,
            'inputs' => [],
            'scores' => [],
        ]))->toThrow(QueryException::class);
});

test('persisted placement decisions are immutable', function () {
    $team = Team::factory()->create();
    createManagedComputeNode();
    $reservation = app(ManagedComputeScheduler::class)
        ->reserve($team, 'application', 1000, 1024, 10, 0, 'immutable-decision', 'in-bom-1');

    expect(fn () => $reservation->decision->update(['explanation' => 'changed']))
        ->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $reservation->decision->delete())
        ->toThrow(RuntimeException::class, 'immutable');
});
