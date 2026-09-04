<?php

namespace App\Contracts\OnePloy;

use App\Models\Server;

interface ManagedNodeProbe
{
    /**
     * @return array{
     *     cpu_millis_total: int,
     *     memory_mb_total: int,
     *     disk_gb_total: int,
     *     gpu_total: int,
     *     raw?: array<string, mixed>
     * }
     */
    public function probe(Server $server): array;
}
