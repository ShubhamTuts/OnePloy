<?php

namespace App\Services\OnePloy;

use App\Models\OneployComputeNode;
use App\Models\OneployComputePool;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ManagedComputeNodeRegistrar
{
    /** @param array<int, string> $workloadClasses */
    public function register(Server $server, string $poolSlug, string $poolName, ?string $region, array $workloadClasses): OneployComputeNode
    {
        $poolSlug = $this->normalizeSlug($poolSlug);
        $poolName = $this->normalizeText($poolName, 'Pool name', 255);
        $region = filled($region) ? $this->normalizeText((string) $region, 'Region', 255) : null;
        $workloadClasses = $this->normalizeWorkloadClasses($workloadClasses);

        return DB::transaction(function () use ($server, $poolSlug, $poolName, $region, $workloadClasses): OneployComputeNode {
            $pool = OneployComputePool::query()->where('slug', $poolSlug)->lockForUpdate()->first();

            if ($pool) {
                $pool->update([
                    'name' => $poolName,
                    'region' => $region,
                    'workload_classes' => $workloadClasses,
                    'is_managed' => true,
                    'is_active' => true,
                ]);
            } else {
                $pool = OneployComputePool::create([
                    'slug' => $poolSlug,
                    'name' => $poolName,
                    'region' => $region,
                    'workload_classes' => $workloadClasses,
                    'is_managed' => true,
                    'is_active' => true,
                ]);
            }

            return OneployComputeNode::query()->firstOrCreate(
                ['compute_pool_id' => $pool->id, 'server_id' => $server->id],
                ['labels' => ['registered_by' => 'oneploy'], 'is_draining' => false],
            )->load(['pool', 'server']);
        }, 3);
    }

    public function setDraining(Server $server, string $poolSlug, bool $isDraining): OneployComputeNode
    {
        $node = OneployComputeNode::query()
            ->where('server_id', $server->id)
            ->whereHas('pool', fn ($query) => $query->where('slug', $this->normalizeSlug($poolSlug)))
            ->first();

        if (! $node) {
            throw new RuntimeException('This server is not registered in the requested compute pool.');
        }

        $node->update(['is_draining' => $isDraining]);

        return $node->fresh(['pool', 'server']);
    }

    private function normalizeSlug(string $value): string
    {
        $slug = Str::slug(Str::lower(trim($value)));

        if ($slug === '' || Str::length($slug) > 255) {
            throw new InvalidArgumentException('Compute pool slug is invalid.');
        }

        return $slug;
    }

    private function normalizeText(string $value, string $label, int $maximumLength): string
    {
        $value = trim($value);

        if ($value === '' || Str::length($value) > $maximumLength) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }

    /** @param array<int, mixed> $workloadClasses
     * @return array<int, string>
     */
    private function normalizeWorkloadClasses(array $workloadClasses): array
    {
        $normalized = collect($workloadClasses)
            ->map(fn (mixed $workloadClass): string => Str::lower(trim((string) $workloadClass)))
            ->filter()
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            throw new InvalidArgumentException('At least one workload class is required.');
        }

        foreach ($normalized as $workloadClass) {
            if (Str::length($workloadClass) > 100 || ! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $workloadClass)) {
                throw new InvalidArgumentException("Workload class [{$workloadClass}] is invalid.");
            }
        }

        return $normalized->all();
    }
}
