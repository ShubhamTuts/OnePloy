<?php

namespace App\Jobs\OnePloy;

use App\Models\OneployComputeNode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ReconcileManagedComputeCapacityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 55;

    public function handle(): void
    {
        OneployComputeNode::query()
            ->select('id')
            ->whereHas('pool', fn (Builder $query) => $query->activeManaged())
            ->chunkById(
                max(1, (int) config('oneploy.scheduler.probe_batch_size', 100)),
                function (Collection $nodes): void {
                    foreach ($nodes as $node) {
                        CaptureManagedComputeCapacityJob::dispatch($node->id);
                    }
                },
            );
    }
}
