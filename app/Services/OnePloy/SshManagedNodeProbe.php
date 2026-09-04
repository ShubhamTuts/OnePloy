<?php

namespace App\Services\OnePloy;

use App\Contracts\OnePloy\ManagedNodeProbe;
use App\Models\Server;
use RuntimeException;

class SshManagedNodeProbe implements ManagedNodeProbe
{
    /**
     * @return array{
     *     cpu_millis_total: int,
     *     memory_mb_total: int,
     *     disk_gb_total: int,
     *     gpu_total: int,
     *     raw: array<string, mixed>
     * }
     */
    public function probe(Server $server): array
    {
        $output = instant_remote_process(
            [
                "printf 'cpu_cores='; getconf _NPROCESSORS_ONLN",
                "printf 'memory_kb='; sed -n 's/^MemTotal:[[:space:]]*\\([0-9]*\\).*/\\1/p' /proc/meminfo",
                "printf 'disk_kb='; { df -Pk /var/lib/docker 2>/dev/null || df -Pk /; } | tail -n 1 | tr -s ' ' | cut -d ' ' -f 2",
                "printf 'gpu_count='; if command -v nvidia-smi >/dev/null 2>&1; then nvidia-smi -L 2>/dev/null | wc -l; else printf '0\\n'; fi",
            ],
            $server,
            throwError: true,
            no_sudo: true,
            timeout: max(5, (int) config('oneploy.scheduler.probe_timeout_seconds', 20)),
        );

        $metrics = $this->parseMetrics($output);

        return [
            'cpu_millis_total' => $metrics['cpu_cores'] * 1000,
            'memory_mb_total' => intdiv($metrics['memory_kb'], 1024),
            'disk_gb_total' => intdiv($metrics['disk_kb'], 1024 * 1024),
            'gpu_total' => $metrics['gpu_count'],
            'raw' => [
                'source' => 'ssh-linux-host-capacity',
                'cpu_cores' => $metrics['cpu_cores'],
                'memory_kb_total' => $metrics['memory_kb'],
                'disk_kb_total' => $metrics['disk_kb'],
            ],
        ];
    }

    /**
     * @return array{cpu_cores: int, memory_kb: int, disk_kb: int, gpu_count: int}
     */
    private function parseMetrics(?string $output): array
    {
        $metrics = [];

        foreach (preg_split('/\R/', trim((string) $output)) ?: [] as $line) {
            if (! preg_match('/^(cpu_cores|memory_kb|disk_kb|gpu_count)=([0-9]+)$/', trim($line), $matches)) {
                continue;
            }

            $metrics[$matches[1]] = (int) $matches[2];
        }

        foreach (['cpu_cores', 'memory_kb', 'disk_kb', 'gpu_count'] as $metric) {
            if (! array_key_exists($metric, $metrics)) {
                throw new RuntimeException('The managed node returned incomplete capacity telemetry.');
            }
        }

        if ($metrics['cpu_cores'] < 1 || $metrics['memory_kb'] < 1024 || $metrics['disk_kb'] < 1024 * 1024) {
            throw new RuntimeException('The managed node returned invalid capacity telemetry.');
        }

        return $metrics;
    }
}
