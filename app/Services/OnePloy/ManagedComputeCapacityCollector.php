<?php

namespace App\Services\OnePloy;

use App\Contracts\OnePloy\ManagedNodeProbe;
use App\Models\OneployCapacitySnapshot;
use App\Models\OneployComputeNode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ManagedComputeCapacityCollector
{
    public function __construct(private readonly ManagedNodeProbe $probe) {}

    public function capture(OneployComputeNode $node): OneployCapacitySnapshot
    {
        $node->loadMissing('server');

        if (! $node->server) {
            throw new RuntimeException('The managed compute node has no server.');
        }

        try {
            $capacity = $this->validatedCapacity($this->probe->probe($node->server));
            $allocatable = $this->allocatableCapacity($capacity);
        } catch (Throwable $exception) {
            $this->recordFailure($node, $exception);

            throw $exception;
        }

        return DB::transaction(function () use ($node, $capacity, $allocatable): OneployCapacitySnapshot {
            $capturedAt = now();
            $snapshot = OneployCapacitySnapshot::create([
                'compute_node_id' => $node->id,
                'cpu_millis_available' => $allocatable['cpu_millis'],
                'memory_mb_available' => $allocatable['memory_mb'],
                'disk_gb_available' => $allocatable['disk_gb'],
                'gpu_available' => $allocatable['gpu'],
                'raw' => [
                    ...($capacity['raw'] ?? []),
                    'totals' => [
                        'cpu_millis' => $capacity['cpu_millis_total'],
                        'memory_mb' => $capacity['memory_mb_total'],
                        'disk_gb' => $capacity['disk_gb_total'],
                        'gpu' => $capacity['gpu_total'],
                    ],
                    'allocation_percent' => $this->allocationPercent(),
                    'system_reserve' => $this->systemReserve(),
                ],
                'captured_at' => $capturedAt,
            ]);

            OneployComputeNode::query()->whereKey($node->id)->update([
                'last_seen_at' => $capturedAt,
                'last_probe_error' => null,
                'consecutive_probe_failures' => 0,
                'updated_at' => $capturedAt,
            ]);

            $this->pruneSnapshots($node);

            return $snapshot;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $capacity
     * @return array{
     *     cpu_millis_total: int,
     *     memory_mb_total: int,
     *     disk_gb_total: int,
     *     gpu_total: int,
     *     raw?: array<string, mixed>
     * }
     */
    private function validatedCapacity(array $capacity): array
    {
        foreach (['cpu_millis_total', 'memory_mb_total', 'disk_gb_total', 'gpu_total'] as $key) {
            if (! isset($capacity[$key]) || ! is_int($capacity[$key])) {
                throw new InvalidArgumentException("Capacity metric [{$key}] must be an integer.");
            }

            $minimum = $key === 'gpu_total' ? 0 : 1;
            if ($capacity[$key] < $minimum || $capacity[$key] > 4_294_967_295) {
                throw new InvalidArgumentException("Capacity metric [{$key}] is outside the supported range.");
            }
        }

        if (isset($capacity['raw']) && ! is_array($capacity['raw'])) {
            throw new InvalidArgumentException('Raw capacity telemetry must be an array.');
        }

        return $capacity;
    }

    /**
     * @param  array{cpu_millis_total: int, memory_mb_total: int, disk_gb_total: int, gpu_total: int}  $capacity
     * @return array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int}
     */
    private function allocatableCapacity(array $capacity): array
    {
        $percent = $this->allocationPercent();
        $reserve = $this->systemReserve();

        return [
            'cpu_millis' => max(0, intdiv($capacity['cpu_millis_total'] * $percent, 100) - $reserve['cpu_millis']),
            'memory_mb' => max(0, intdiv($capacity['memory_mb_total'] * $percent, 100) - $reserve['memory_mb']),
            'disk_gb' => max(0, intdiv($capacity['disk_gb_total'] * $percent, 100) - $reserve['disk_gb']),
            'gpu' => $capacity['gpu_total'],
        ];
    }

    private function allocationPercent(): int
    {
        $percent = (int) config('oneploy.scheduler.capacity_allocation_percent', 80);

        if ($percent < 1 || $percent > 100) {
            throw new InvalidArgumentException('Managed capacity allocation percent must be between 1 and 100.');
        }

        return $percent;
    }

    /**
     * @return array{cpu_millis: int, memory_mb: int, disk_gb: int}
     */
    private function systemReserve(): array
    {
        $reserve = [
            'cpu_millis' => (int) config('oneploy.scheduler.system_reserved_cpu_millis', 500),
            'memory_mb' => (int) config('oneploy.scheduler.system_reserved_memory_mb', 1024),
            'disk_gb' => (int) config('oneploy.scheduler.system_reserved_disk_gb', 20),
        ];

        foreach ($reserve as $resource => $quantity) {
            if ($quantity < 0 || $quantity > 4_294_967_295) {
                throw new InvalidArgumentException("Managed system reserve [{$resource}] is outside the supported range.");
            }
        }

        return $reserve;
    }

    private function recordFailure(OneployComputeNode $node, Throwable $exception): void
    {
        OneployComputeNode::query()->whereKey($node->id)->update([
            'last_probe_failed_at' => now(),
            'last_probe_error' => $exception::class,
            'consecutive_probe_failures' => min(65_535, ((int) $node->consecutive_probe_failures) + 1),
            'updated_at' => now(),
        ]);
    }

    private function pruneSnapshots(OneployComputeNode $node): void
    {
        $retention = max(1, (int) config('oneploy.scheduler.snapshot_retention_per_node', 120));
        $retainedIds = $node->capacitySnapshots()
            ->latest('captured_at')
            ->latest('id')
            ->limit($retention)
            ->pluck('id');

        $node->capacitySnapshots()->whereNotIn('id', $retainedIds)->delete();
    }
}
