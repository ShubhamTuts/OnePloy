<?php

namespace App\Console\Commands;

use App\Jobs\OnePloy\CaptureManagedComputeCapacityJob;
use App\Models\OneployComputeNode;
use App\Models\Server;
use App\Services\OnePloy\ManagedComputeCapacityCollector;
use App\Services\OnePloy\ManagedComputeNodeRegistrar;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class OnePloyComputeNodeCommand extends Command
{
    protected $signature = 'oneploy:compute-node
        {action : register, drain, undrain, probe, or status}
        {server=0 : Server primary key}
        {--pool=default : Compute pool slug}
        {--name=Default Managed Pool : Compute pool display name}
        {--region=local : Compute pool region}
        {--workload-class=* : Supported workload classes}';

    protected $description = 'Register, probe, inspect, drain, or undrain a OnePloy managed compute node';

    public function handle(ManagedComputeNodeRegistrar $registrar, ManagedComputeCapacityCollector $collector): int
    {
        $action = strtolower((string) $this->argument('action'));
        $server = Server::query()->find($this->argument('server'));

        if (! $server) {
            $this->error('Server not found.');

            return self::FAILURE;
        }

        try {
            return match ($action) {
                'register' => $this->register($registrar, $server),
                'drain' => $this->setDraining($registrar, $server, true),
                'undrain' => $this->setDraining($registrar, $server, false),
                'probe' => $this->probe($collector, $server),
                'status' => $this->status($server),
                default => $this->invalidAction(),
            };
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function register(ManagedComputeNodeRegistrar $registrar, Server $server): int
    {
        $workloadClasses = $this->option('workload-class') ?: ['application', 'service', 'database'];
        $node = $registrar->register($server, (string) $this->option('pool'), (string) $this->option('name'), (string) $this->option('region'), $workloadClasses);
        CaptureManagedComputeCapacityJob::dispatch($node->id);
        $this->info("Server {$server->id} registered in managed pool {$node->pool->slug}; capacity probe queued.");

        return self::SUCCESS;
    }

    private function setDraining(ManagedComputeNodeRegistrar $registrar, Server $server, bool $isDraining): int
    {
        $node = $registrar->setDraining($server, (string) $this->option('pool'), $isDraining);
        $state = $node->is_draining ? 'draining' : 'accepting workloads';
        $this->info("Server {$server->id} is {$state} in managed pool {$node->pool->slug}.");

        return self::SUCCESS;
    }

    private function probe(ManagedComputeCapacityCollector $collector, Server $server): int
    {
        $node = $this->node($server);
        $snapshot = $collector->capture($node);
        $this->table(['CPU millicores', 'Memory MB', 'Disk GB', 'GPU', 'Captured'], [[
            $snapshot->cpu_millis_available,
            $snapshot->memory_mb_available,
            $snapshot->disk_gb_available,
            $snapshot->gpu_available,
            $snapshot->captured_at?->toIso8601String(),
        ]]);

        return self::SUCCESS;
    }

    private function status(Server $server): int
    {
        $nodes = OneployComputeNode::query()->with(['pool', 'latestCapacitySnapshot'])->where('server_id', $server->id)->orderBy('id')->get();

        if ($nodes->isEmpty()) {
            $this->warn('This server is not registered in a managed compute pool.');

            return self::SUCCESS;
        }

        $this->table(
            ['Pool', 'Region', 'Draining', 'Last seen', 'Probe failures', 'Last error'],
            $nodes->map(fn (OneployComputeNode $node): array => [
                $node->pool?->slug,
                $node->pool?->region,
                $node->is_draining ? 'yes' : 'no',
                $node->last_seen_at?->toIso8601String() ?? 'never',
                $node->consecutive_probe_failures,
                $node->last_probe_error ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function node(Server $server): OneployComputeNode
    {
        $node = OneployComputeNode::query()
            ->where('server_id', $server->id)
            ->whereHas('pool', fn ($query) => $query->where('slug', (string) $this->option('pool')))
            ->first();

        if (! $node) {
            throw new RuntimeException('This server is not registered in the requested compute pool.');
        }

        return $node;
    }

    private function invalidAction(): int
    {
        $this->error('Action must be register, drain, undrain, probe, or status.');

        return self::FAILURE;
    }
}
