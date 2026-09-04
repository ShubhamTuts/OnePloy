<?php

namespace App\Jobs\OnePloy;

use App\Models\OneployComputeNode;
use App\Services\OnePloy\ManagedComputeCapacityCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CaptureManagedComputeCapacityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 55;

    /** @var array<int, int> */
    public array $backoff = [5, 15];

    public function __construct(public readonly int $computeNodeId) {}

    public function uniqueId(): string
    {
        return (string) $this->computeNodeId;
    }

    public function handle(ManagedComputeCapacityCollector $collector): void
    {
        $node = OneployComputeNode::query()
            ->with(['pool', 'server'])
            ->find($this->computeNodeId);

        if (! $node || ! $node->pool?->is_managed || ! $node->pool->is_active) {
            return;
        }

        $collector->capture($node);
    }
}
