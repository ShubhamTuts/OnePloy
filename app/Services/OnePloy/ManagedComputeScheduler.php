<?php

namespace App\Services\OnePloy;

use App\Models\OneployCapacitySnapshot;
use App\Models\OneployComputeNode;
use App\Models\OneployPlacementDecision;
use App\Models\OneployWorkloadReservation;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ManagedComputeScheduler
{
    /**
     * Reserve capacity on a platform-selected node.
     *
     * This is a trusted internal boundary. The caller supplies an authorized
     * team and workload requirements, never a platform pool or node identifier.
     */
    public function reserve(
        Team $team,
        string $workloadClass,
        int $cpuMillis,
        int $memoryMb,
        int $diskGb,
        int $gpu,
        string $idempotencyKey,
        ?string $region = null,
    ): OneployWorkloadReservation {
        $workloadClass = $this->normalizeRequiredIdentifier($workloadClass, 'Workload class', 100);
        $idempotencyKey = $this->normalizeRequiredIdentifier($idempotencyKey, 'Idempotency key', 255);
        $region = $this->normalizeRegion($region);
        $requirements = $this->validateRequirements($cpuMillis, $memoryMb, $diskGb, $gpu);

        return DB::transaction(function () use (
            $team,
            $workloadClass,
            $requirements,
            $idempotencyKey,
            $region,
        ): OneployWorkloadReservation {
            $lockedTeam = Team::query()
                ->whereKey($team->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = OneployWorkloadReservation::query()
                ->with('decision')
                ->where('team_id', $lockedTeam->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->assertIdempotentRetryMatches(
                    $existing,
                    $workloadClass,
                    $requirements,
                    $region,
                );
            }

            if (! $lockedTeam->isTenantActive()) {
                throw new RuntimeException('This tenant is not allowed to schedule workloads.');
            }

            $now = now();
            $eligibleNodes = $this->lockEligibleNodes($workloadClass, $region);

            if ($eligibleNodes->isEmpty()) {
                throw new RuntimeException('No active managed compute nodes support this workload class and region.');
            }

            $maximumSnapshotAge = max(
                1,
                (int) config('oneploy.scheduler.capacity_snapshot_max_age_seconds', 120),
            );
            $freshNodes = $eligibleNodes
                ->filter(function (OneployComputeNode $node) use ($now, $maximumSnapshotAge): bool {
                    $snapshot = $node->latestCapacitySnapshot;

                    return $snapshot?->hasCompleteCapacity() === true
                        && $snapshot->isFresh($now, $maximumSnapshotAge);
                })
                ->values();

            if ($freshNodes->isEmpty()) {
                throw new RuntimeException('No eligible compute node has a fresh capacity snapshot.');
            }

            $activeReservations = OneployWorkloadReservation::query()
                ->select([
                    'id',
                    'compute_node_id',
                    'cpu_millis',
                    'memory_mb',
                    'disk_gb',
                    'gpu',
                ])
                ->whereIn('compute_node_id', $freshNodes->modelKeys())
                ->active($now)
                ->orderBy('compute_node_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $claimedByNode = $this->claimedResourcesByNode($activeReservations);
            $candidates = $freshNodes
                ->map(fn (OneployComputeNode $node): ?array => $this->scoreCandidate(
                    $node,
                    $requirements,
                    $claimedByNode->get($node->getKey(), $this->emptyResourceVector()),
                ))
                ->filter()
                ->values()
                ->all();

            if ($candidates === []) {
                throw new RuntimeException('Insufficient compute capacity for this workload.');
            }

            usort($candidates, fn (array $left, array $right): int => $this->compareCandidates($left, $right));
            $selected = $candidates[0];
            $expiresAt = $now->copy()->addSeconds(max(
                1,
                (int) config('oneploy.scheduler.reservation_ttl_seconds', 300),
            ));
            $reservation = OneployWorkloadReservation::create([
                'compute_node_id' => $selected['node']->getKey(),
                'team_id' => $lockedTeam->getKey(),
                'workload_class' => $workloadClass,
                'idempotency_key' => $idempotencyKey,
                'requirements' => $requirements,
                ...$requirements,
                'expires_at' => $expiresAt,
            ]);
            $decision = OneployPlacementDecision::create([
                'workload_reservation_id' => $reservation->getKey(),
                'compute_node_id' => $selected['node']->getKey(),
                'inputs' => [
                    'workload_class' => $workloadClass,
                    'requirements' => $requirements,
                    'region' => $region,
                    'capacity_snapshot_id' => $selected['snapshot']->getKey(),
                    'capacity_captured_at' => $selected['snapshot']->captured_at->toISOString(),
                ],
                'scores' => $selected['scores'],
                'explanation' => 'Selected by lowest reservation load, highest post-placement headroom, then stable node order.',
            ]);

            return $reservation->setRelation('decision', $decision);
        }, 3);
    }

    public function consume(
        Team $team,
        OneployWorkloadReservation $reservation,
        string $workloadReference,
    ): OneployWorkloadReservation {
        $workloadReference = $this->normalizeRequiredIdentifier(
            $workloadReference,
            'Workload reference',
            255,
        );

        return DB::transaction(function () use ($team, $reservation, $workloadReference): OneployWorkloadReservation {
            $locked = $this->lockReservationForTeam($team, $reservation);

            if ($locked->status === OneployWorkloadReservation::STATUS_CONSUMED) {
                if ($locked->workload_reference !== $workloadReference) {
                    throw new RuntimeException('This reservation was consumed by a different workload.');
                }

                return $locked;
            }

            if ($locked->status === OneployWorkloadReservation::STATUS_RELEASED) {
                throw new RuntimeException('A released reservation cannot be consumed.');
            }

            if ($locked->status === OneployWorkloadReservation::STATUS_EXPIRED || ! $locked->isActive()) {
                throw new RuntimeException('An expired reservation cannot be consumed.');
            }

            $locked->update([
                'status' => OneployWorkloadReservation::STATUS_CONSUMED,
                'workload_reference' => $workloadReference,
                'expires_at' => null,
                'consumed_at' => now(),
            ]);

            return $locked->fresh(['decision']);
        }, 3);
    }

    public function release(
        Team $team,
        OneployWorkloadReservation $reservation,
    ): OneployWorkloadReservation {
        return DB::transaction(function () use ($team, $reservation): OneployWorkloadReservation {
            $locked = $this->lockReservationForTeam($team, $reservation);

            if ($locked->status === OneployWorkloadReservation::STATUS_RELEASED
                || $locked->status === OneployWorkloadReservation::STATUS_EXPIRED) {
                return $locked;
            }

            $locked->update([
                'status' => OneployWorkloadReservation::STATUS_RELEASED,
                'expires_at' => null,
                'released_at' => now(),
            ]);

            return $locked->fresh(['decision']);
        }, 3);
    }

    public function expire(?CarbonInterface $at = null): int
    {
        $at ??= now();

        return OneployWorkloadReservation::query()
            ->where('status', OneployWorkloadReservation::STATUS_RESERVED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at)
            ->update([
                'status' => OneployWorkloadReservation::STATUS_EXPIRED,
                'updated_at' => $at,
            ]);
    }

    /**
     * Locks nodes in a stable global order so overlapping placement requests
     * cannot reserve the same observed capacity or deadlock each other.
     *
     * @return Collection<int, OneployComputeNode>
     */
    private function lockEligibleNodes(string $workloadClass, ?string $region): Collection
    {
        return OneployComputeNode::query()
            ->select([
                'id',
                'compute_pool_id',
                'server_id',
                'labels',
                'is_draining',
            ])
            ->acceptingWorkloads()
            ->whereHas('pool', function (Builder $poolQuery) use ($region): void {
                $poolQuery
                    ->activeManaged()
                    ->when(
                        $region !== null,
                        fn (Builder $regionQuery) => $regionQuery->where('region', $region),
                    );
            })
            ->with([
                'pool:id,slug,name,region,workload_classes,is_managed,is_active',
                'latestCapacitySnapshot' => fn (HasOne $snapshotQuery) => $snapshotQuery->lockForUpdate(),
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (OneployComputeNode $node): bool => $node->pool->supportsWorkloadClass($workloadClass))
            ->values();
    }

    /**
     * @param  Collection<int, OneployWorkloadReservation>  $reservations
     * @return Collection<int|string, array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int}>
     */
    private function claimedResourcesByNode(Collection $reservations): Collection
    {
        return $reservations
            ->groupBy('compute_node_id')
            ->map(fn (Collection $nodeReservations): array => [
                'cpu_millis' => (int) $nodeReservations->sum('cpu_millis'),
                'memory_mb' => (int) $nodeReservations->sum('memory_mb'),
                'disk_gb' => (int) $nodeReservations->sum('disk_gb'),
                'gpu' => (int) $nodeReservations->sum('gpu'),
            ]);
    }

    /**
     * @param  array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int}  $requirements
     * @param  array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int}  $claimed
     * @return array{
     *     node: OneployComputeNode,
     *     snapshot: OneployCapacitySnapshot,
     *     scores: array{
     *         load_basis_points: int,
     *         headroom_basis_points: int,
     *         reserved: array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int},
     *         available_before: array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int},
     *         headroom_after: array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int}
     *     }
     * }|null
     */
    private function scoreCandidate(
        OneployComputeNode $node,
        array $requirements,
        array $claimed,
    ): ?array {
        $snapshot = $node->latestCapacitySnapshot;

        if (! $snapshot instanceof OneployCapacitySnapshot) {
            return null;
        }

        $capacity = $snapshot->availableResources();
        $availableBefore = $this->emptyResourceVector();
        $headroomAfter = $this->emptyResourceVector();
        $loadRatios = [];
        $headroomRatios = [];

        foreach (array_keys($requirements) as $resource) {
            $availableBefore[$resource] = max(0, $capacity[$resource] - $claimed[$resource]);

            if ($availableBefore[$resource] < $requirements[$resource]) {
                return null;
            }

            $headroomAfter[$resource] = $availableBefore[$resource] - $requirements[$resource];

            if ($capacity[$resource] > 0) {
                $loadRatios[] = intdiv($claimed[$resource] * 10_000, $capacity[$resource]);
                $headroomRatios[] = intdiv($headroomAfter[$resource] * 10_000, $capacity[$resource]);
            }
        }

        return [
            'node' => $node,
            'snapshot' => $snapshot,
            'scores' => [
                'load_basis_points' => max($loadRatios),
                'headroom_basis_points' => min($headroomRatios),
                'reserved' => $claimed,
                'available_before' => $availableBefore,
                'headroom_after' => $headroomAfter,
            ],
        ];
    }

    /**
     * @param  array{node: OneployComputeNode, scores: array{load_basis_points: int, headroom_basis_points: int}}  $left
     * @param  array{node: OneployComputeNode, scores: array{load_basis_points: int, headroom_basis_points: int}}  $right
     */
    private function compareCandidates(array $left, array $right): int
    {
        return $left['scores']['load_basis_points'] <=> $right['scores']['load_basis_points']
            ?: $right['scores']['headroom_basis_points'] <=> $left['scores']['headroom_basis_points']
            ?: $left['node']->getKey() <=> $right['node']->getKey();
    }

    private function lockReservationForTeam(
        Team $team,
        OneployWorkloadReservation $reservation,
    ): OneployWorkloadReservation {
        $lockedTeam = Team::query()
            ->whereKey($team->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        $lockedReservation = OneployWorkloadReservation::query()
            ->with('decision')
            ->whereKey($reservation->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($lockedReservation->team_id !== $lockedTeam->getKey()) {
            throw new RuntimeException('This reservation does not belong to this team.');
        }

        return $lockedReservation;
    }

    /**
     * @param  array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int}  $requirements
     */
    private function assertIdempotentRetryMatches(
        OneployWorkloadReservation $reservation,
        string $workloadClass,
        array $requirements,
        ?string $region,
    ): OneployWorkloadReservation {
        $decisionInputs = $reservation->decision?->inputs;

        if ($reservation->workload_class !== $workloadClass
            || $reservation->requestedResources() !== $requirements
            || ! is_array($decisionInputs)
            || ($decisionInputs['region'] ?? null) !== $region) {
            throw new RuntimeException('This idempotency key was used with different placement inputs.');
        }

        return $reservation;
    }

    /**
     * @return array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int}
     */
    private function validateRequirements(
        int $cpuMillis,
        int $memoryMb,
        int $diskGb,
        int $gpu,
    ): array {
        $requirements = [
            'cpu_millis' => $cpuMillis,
            'memory_mb' => $memoryMb,
            'disk_gb' => $diskGb,
            'gpu' => $gpu,
        ];

        foreach (['cpu_millis', 'memory_mb', 'disk_gb'] as $resource) {
            if ($requirements[$resource] < 1) {
                throw new InvalidArgumentException("{$resource} must be at least one.");
            }
        }

        if ($gpu < 0) {
            throw new InvalidArgumentException('gpu cannot be negative.');
        }

        foreach ($requirements as $resource => $quantity) {
            if ($quantity > 4_294_967_295) {
                throw new InvalidArgumentException("{$resource} exceeds the supported maximum.");
            }
        }

        return $requirements;
    }

    private function normalizeRequiredIdentifier(string $value, string $label, int $maximumLength): string
    {
        $normalized = (string) Str::of($value)->trim();

        if ($normalized === '') {
            throw new InvalidArgumentException("{$label} is required.");
        }

        if (Str::length($normalized) > $maximumLength) {
            throw new InvalidArgumentException("{$label} cannot exceed {$maximumLength} characters.");
        }

        return $normalized;
    }

    private function normalizeRegion(?string $region): ?string
    {
        if ($region === null) {
            return null;
        }

        return $this->normalizeRequiredIdentifier($region, 'Region', 255);
    }

    /**
     * @return array{cpu_millis: int, memory_mb: int, disk_gb: int, gpu: int}
     */
    private function emptyResourceVector(): array
    {
        return [
            'cpu_millis' => 0,
            'memory_mb' => 0,
            'disk_gb' => 0,
            'gpu' => 0,
        ];
    }
}
