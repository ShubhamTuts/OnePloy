<?php

namespace App\Jobs\OnePloy;

use App\Models\OneployDnsZone;
use App\Models\OneployDomain;
use App\Models\User;
use App\Notifications\TransactionalEmails\DomainRegistered;
use App\Services\OnePloy\ConnectResellerClient;
use App\Services\OnePloy\PowerDnsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionDomainJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $domainId) {}

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    public function handle(ConnectResellerClient $registrar, PowerDnsClient $powerDns): void
    {
        $domain = DB::transaction(function (): ?OneployDomain {
            $domain = OneployDomain::query()
                ->with('checkoutSession')
                ->whereKey($this->domainId)
                ->lockForUpdate()
                ->first();

            if (! $domain || in_array($domain->status, ['registered', 'active'], true)) {
                return null;
            }
            if ($domain->status !== 'pending_registration' || $domain->checkoutSession?->status !== 'paid') {
                return null;
            }

            $domain->update([
                'status' => 'registering',
                'provisioning_attempts' => $domain->provisioning_attempts + 1,
                'last_error' => null,
            ]);

            return $domain->fresh(['checkoutSession']);
        });

        if (! $domain) {
            return;
        }

        try {
            $registration = $registrar->register(
                $domain->name,
                $domain->years,
                $domain->privacy,
                $domain->nameservers ?? [],
                $domain->contact_payload ?? [],
            );
        } catch (Throwable $exception) {
            $domain->update([
                'status' => 'manual_review',
                'last_error' => $exception->getMessage(),
            ]);
            report($exception);
            Log::warning('oneploy.domain.registration_manual_review', [
                'domain_id' => $domain->id,
                'domain' => $domain->name,
            ]);

            return;
        }

        $domain->update([
            'status' => 'registered',
            'provider_reference' => $registration['provider_reference'],
            'expires_at' => $this->expiryDate($registration['expires_at'], $domain->years),
            'provisioned_at' => now(),
            'last_error' => null,
        ]);

        $dnsActive = false;
        if ($powerDns->isConfigured()) {
            try {
                $zone = $powerDns->ensureZone($domain->name);
                OneployDnsZone::query()->updateOrCreate(
                    ['domain_id' => $domain->id],
                    [
                        'team_id' => $domain->team_id,
                        'name' => $domain->name,
                        'status' => 'pending_delegation',
                        'records' => data_get($zone, 'rrsets', []),
                        'dnssec' => (bool) data_get($zone, 'dnssec', false),
                    ],
                );
                $domain->update([
                    'status' => 'dns_pending',
                    'last_error' => 'Registration succeeded; waiting for public nameserver delegation verification.',
                ]);
                VerifyDomainDnsJob::dispatch($domain->id)->delay(now()->addMinutes(2));
            } catch (Throwable $exception) {
                report($exception);
                $domain->update(['last_error' => 'Registration succeeded, but authoritative DNS activation needs attention.']);
            }
        } else {
            $domain->update(['last_error' => 'Registration succeeded, but authoritative DNS is not configured.']);
        }

        $user = User::find($domain->checkoutSession?->user_id);
        $user?->notify(new DomainRegistered(
            domain: $domain->name,
            nameservers: $domain->nameservers ?? [],
            dnsActive: $dnsActive,
        ));
    }

    public function failed(?Throwable $exception): void
    {
        OneployDomain::query()->whereKey($this->domainId)->update([
            'status' => 'manual_review',
            'last_error' => 'Domain provisioning stopped unexpectedly and requires operator review.',
        ]);

        Log::error('oneploy.domain.provisioning_job_failed', [
            'domain_id' => $this->domainId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    private function expiryDate(?string $providerExpiry, int $years): Carbon
    {
        if ($providerExpiry) {
            try {
                return Carbon::parse($providerExpiry);
            } catch (Throwable) {
            }
        }

        return now()->addYears($years);
    }
}
