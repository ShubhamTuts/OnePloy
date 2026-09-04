<?php

use App\Contracts\OnePloy\ManagedNodeProbe;
use App\Jobs\OnePloy\CaptureManagedComputeCapacityJob;
use App\Jobs\OnePloy\ReconcileManagedComputeCapacityJob;
use App\Models\InstanceSettings;
use App\Models\OneployCapacitySnapshot;
use App\Models\OneployComputeNode;
use App\Models\OneployComputePool;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\OnePloy\ManagedComputeCapacityCollector;
use App\Services\OnePloy\ManagedComputeScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('oneploy.scheduler.capacity_allocation_percent', 80);
    config()->set('oneploy.scheduler.system_reserved_cpu_millis', 500);
    config()->set('oneploy.scheduler.system_reserved_memory_mb', 800);
    config()->set('oneploy.scheduler.system_reserved_disk_gb', 20);
    config()->set('oneploy.scheduler.snapshot_retention_per_node', 2);
});

function createCapacityCollectorServer(): Server
{
    $privateKey = PrivateKey::query()->first();

    if (! $privateKey) {
        $infrastructureTeam = Team::factory()->create();
        $privateKey = PrivateKey::factory()->create(['team_id' => $infrastructureTeam->id]);
    } else {
        $infrastructureTeam = Team::query()->findOrFail($privateKey->team_id);
    }

    return Server::factory()->create([
        'team_id' => $infrastructureTeam->id,
        'private_key_id' => $privateKey->id,
    ]);
}

function createCapacityCollectorNode(array $poolOverrides = []): OneployComputeNode
{
    $server = createCapacityCollectorServer();
    $pool = OneployComputePool::create(array_merge([
        'slug' => fake()->unique()->slug(),
        'name' => fake()->unique()->words(2, true),
        'region' => 'in-bom-1',
        'workload_classes' => ['application'],
        'is_managed' => true,
        'is_active' => true,
    ], $poolOverrides));

    return OneployComputeNode::create([
        'compute_pool_id' => $pool->id,
        'server_id' => $server->id,
        'labels' => ['tier' => 'general'],
    ]);
}

test('collector converts total node resources into conservative allocatable capacity and bounds history', function () {
    $node = createCapacityCollectorNode();
    foreach ([5, 4] as $minutesAgo) {
        OneployCapacitySnapshot::create([
            'compute_node_id' => $node->id,
            'cpu_millis_available' => 1000,
            'memory_mb_available' => 1000,
            'disk_gb_available' => 10,
            'gpu_available' => 0,
            'captured_at' => now()->subMinutes($minutesAgo),
        ]);
    }
    $probe = new class implements ManagedNodeProbe
    {
        public function probe(Server $server): array
        {
            return [
                'cpu_millis_total' => 8000,
                'memory_mb_total' => 16000,
                'disk_gb_total' => 200,
                'gpu_total' => 2,
                'raw' => ['source' => 'test-probe'],
            ];
        }
    };

    $snapshot = (new ManagedComputeCapacityCollector($probe))->capture($node);

    expect($snapshot->availableResources())->toBe([
        'cpu_millis' => 5900,
        'memory_mb' => 12000,
        'disk_gb' => 140,
        'gpu' => 2,
    ])->and($snapshot->raw)->toMatchArray([
        'source' => 'test-probe',
        'allocation_percent' => 80,
    ])->and($node->fresh()->last_seen_at)->not->toBeNull()
        ->and($node->fresh()->last_probe_error)->toBeNull()
        ->and($node->fresh()->consecutive_probe_failures)->toBe(0)
        ->and($node->capacitySnapshots()->count())->toBe(2);
});

test('failed probes immediately quarantine a node even while its prior snapshot is fresh', function () {
    $node = createCapacityCollectorNode();
    OneployCapacitySnapshot::create([
        'compute_node_id' => $node->id,
        'cpu_millis_available' => 8000,
        'memory_mb_available' => 16000,
        'disk_gb_available' => 200,
        'gpu_available' => 0,
        'captured_at' => now(),
    ]);
    $probe = new class implements ManagedNodeProbe
    {
        public function probe(Server $server): array
        {
            throw new RuntimeException('sensitive transport details');
        }
    };

    expect(fn () => (new ManagedComputeCapacityCollector($probe))->capture($node))
        ->toThrow(RuntimeException::class, 'sensitive transport details');

    $node->refresh();

    expect($node->last_probe_failed_at)->not->toBeNull()
        ->and($node->last_probe_error)->toBe(RuntimeException::class)
        ->and($node->consecutive_probe_failures)->toBe(1)
        ->and(fn () => app(ManagedComputeScheduler::class)->reserve(
            Team::factory()->create(),
            'application',
            1000,
            1024,
            10,
            0,
            'unhealthy-node',
            'in-bom-1',
        ))->toThrow(RuntimeException::class, 'No active managed compute nodes');
});

test('capacity reconciliation only queues probes for active managed pools', function () {
    Queue::fake();
    $eligible = createCapacityCollectorNode();
    createCapacityCollectorNode(['is_active' => false]);
    createCapacityCollectorNode(['is_managed' => false]);

    (new ReconcileManagedComputeCapacityJob)->handle();

    Queue::assertPushed(
        CaptureManagedComputeCapacityJob::class,
        fn (CaptureManagedComputeCapacityJob $job): bool => $job->computeNodeId === $eligible->id,
    );
    Queue::assertPushed(CaptureManagedComputeCapacityJob::class, 1);
});

test('operator command idempotently registers and drains a managed compute node', function () {
    Queue::fake();
    $server = createCapacityCollectorServer();
    $arguments = [
        'action' => 'register',
        'server' => (string) $server->id,
        '--pool' => 'india-primary',
        '--name' => 'India Primary',
        '--region' => 'in-bom-1',
        '--workload-class' => ['application', 'database'],
    ];

    $this->artisan('oneploy:compute-node', $arguments)
        ->expectsOutputToContain('registered')
        ->assertSuccessful();
    $this->artisan('oneploy:compute-node', $arguments)->assertSuccessful();

    $pool = OneployComputePool::query()->where('slug', 'india-primary')->firstOrFail();
    $node = OneployComputeNode::query()
        ->where('compute_pool_id', $pool->id)
        ->where('server_id', $server->id)
        ->firstOrFail();

    expect($pool->workload_classes)->toBe(['application', 'database'])
        ->and(OneployComputeNode::query()->where('server_id', $server->id)->count())->toBe(1)
        ->and($node->is_draining)->toBeFalse();

    $this->artisan('oneploy:compute-node', [
        'action' => 'drain',
        'server' => (string) $server->id,
        '--pool' => 'india-primary',
    ])->assertSuccessful();

    expect($node->fresh()->is_draining)->toBeTrue();

    $this->artisan('oneploy:compute-node', [
        'action' => 'undrain',
        'server' => (string) $server->id,
        '--pool' => 'india-primary',
    ])->assertSuccessful();

    expect($node->fresh()->is_draining)->toBeFalse();
});
