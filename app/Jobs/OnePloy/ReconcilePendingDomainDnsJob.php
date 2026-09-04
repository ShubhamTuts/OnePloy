<?php

namespace App\Jobs\OnePloy;

use App\Models\OneployDomain;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePendingDomainDnsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 240;

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(): void
    {
        OneployDomain::query()
            ->select(['id'])
            ->where('status', 'dns_pending')
            ->whereHas('dnsZone', fn ($query) => $query->where('status', 'pending_delegation'))
            ->oldest('id')
            ->limit(max(1, (int) config('oneploy.dns.verification_batch_size', 100)))
            ->pluck('id')
            ->each(fn (int $domainId) => VerifyDomainDnsJob::dispatch($domainId));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('oneploy.domain.dns_reconciliation_failed', [
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
