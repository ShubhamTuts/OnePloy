<?php

namespace App\Jobs\OnePloy;

use App\Models\OneployDomain;
use App\Services\OnePloy\DnsDelegationVerifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifyDomainDnsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 240;

    public function __construct(public int $domainId) {}

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(DnsDelegationVerifier $verifier): void
    {
        $domain = OneployDomain::query()
            ->with('dnsZone')
            ->whereKey($this->domainId)
            ->first();

        if (! $domain || $domain->status === 'active' || $domain->status !== 'dns_pending') {
            return;
        }

        if (! $domain->dnsZone || ! $verifier->isDelegated($domain->name, $domain->nameservers ?? [])) {
            $domain->update([
                'last_error' => 'Registration succeeded; waiting for the public nameserver delegation to agree across independent resolvers.',
            ]);

            return;
        }

        DB::transaction(function (): void {
            $locked = OneployDomain::query()
                ->with('dnsZone')
                ->whereKey($this->domainId)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== 'dns_pending' || ! $locked->dnsZone) {
                return;
            }

            $locked->dnsZone->update(['status' => 'active']);
            $locked->update([
                'status' => 'active',
                'last_error' => null,
            ]);
        }, 3);
    }

    public function failed(?Throwable $exception): void
    {
        OneployDomain::query()->whereKey($this->domainId)->update([
            'last_error' => 'Public DNS verification is delayed and will be retried automatically.',
        ]);

        Log::warning('oneploy.domain.dns_verification_failed', [
            'domain_id' => $this->domainId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
