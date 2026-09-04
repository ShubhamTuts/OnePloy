<?php

namespace App\Services\OnePloy;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PowerDnsClient
{
    public function isConfigured(): bool
    {
        $baseConfigured = filled(config('oneploy.dns.powerdns_api_url'))
            && filled(config('oneploy.dns.powerdns_api_key'))
            && count(config('oneploy.dns.nameservers', [])) >= 2;

        return $baseConfigured
            && (! config('oneploy.dns.require_ha', false) || $this->isHighAvailabilityConfigured());
    }

    public function isHighAvailabilityConfigured(): bool
    {
        $primaryUrl = rtrim((string) config('oneploy.dns.powerdns_api_url'), '/');
        $primarySite = (string) config('oneploy.dns.primary_site');
        $secondaries = config('oneploy.dns.secondaries', []);

        if ($primaryUrl === '' || $primarySite === '' || ! is_array($secondaries) || $secondaries === []) {
            return false;
        }

        foreach ($secondaries as $secondary) {
            if (! is_array($secondary)) {
                return false;
            }

            $secondaryApiUrl = $secondary['api_url'] ?? null;

            if (! is_string($secondaryApiUrl)
                || parse_url($secondaryApiUrl, PHP_URL_SCHEME) !== 'https'
                || blank(parse_url($secondaryApiUrl, PHP_URL_HOST))
                || rtrim($secondaryApiUrl, '/') === $primaryUrl
                || blank($secondary['api_key'] ?? null)
                || blank($secondary['server_id'] ?? null)
                || blank($secondary['site'] ?? null)
                || hash_equals($primarySite, (string) $secondary['site'])
                || ! filter_var($secondary['master_ip'] ?? null, FILTER_VALIDATE_IP)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function ensureZone(string $domain): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('PowerDNS API and at least two nameservers must be configured.');
        }

        $zoneName = rtrim(strtolower($domain), '.').'.';
        $highAvailability = $this->isHighAvailabilityConfigured();
        $response = $this->request()->post($this->zonesPath(), [
            'name' => $zoneName,
            'kind' => $highAvailability ? 'Primary' : 'Native',
            'masters' => [],
            'nameservers' => collect(config('oneploy.dns.nameservers'))
                ->map(fn (string $nameserver): string => rtrim(strtolower($nameserver), '.').'.')
                ->values()
                ->all(),
            'dnssec' => (bool) config('oneploy.dns.dnssec', false),
            'api_rectify' => true,
        ]);

        if ($response->successful()) {
            $zone = $response->json();
        } elseif ($response->status() === 409 || str_contains(strtolower($response->body()), 'already exists')) {
            $zone = $this->zone($domain);
        } else {
            $response->throw();

            return [];
        }

        if ($highAvailability) {
            foreach (config('oneploy.dns.secondaries', []) as $secondary) {
                $this->ensureSecondaryZone($zoneName, $secondary);
            }
        }

        return $zone;
    }

    /** @return array<string, mixed> */
    public function zone(string $domain): array
    {
        $zoneName = rtrim(strtolower($domain), '.').'.';

        return $this->request()
            ->get($this->zonesPath().'/'.rawurlencode($zoneName))
            ->throw()
            ->json();
    }

    /**
     * @param  list<string>  $records
     */
    public function replaceRecords(string $domain, string $name, string $type, array $records, int $ttl = 300): void
    {
        $zoneName = rtrim(strtolower($domain), '.').'.';
        $recordName = rtrim(strtolower($name), '.').'.';

        $this->request()
            ->patch($this->zonesPath().'/'.rawurlencode($zoneName), [
                'rrsets' => [[
                    'name' => $recordName,
                    'type' => strtoupper($type),
                    'ttl' => $ttl,
                    'changetype' => 'REPLACE',
                    'records' => collect($records)
                        ->map(fn (string $content): array => ['content' => $content, 'disabled' => false])
                        ->all(),
                ]],
            ])
            ->throw();
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('PowerDNS is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('oneploy.dns.powerdns_api_url'), '/'))
            ->withHeaders(['X-API-Key' => (string) config('oneploy.dns.powerdns_api_key')])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->connectTimeout(3)
            ->retry([200, 500, 1000], throw: false);
    }

    /**
     * @param  array<string, mixed>  $secondary
     */
    private function ensureSecondaryZone(string $zoneName, array $secondary): void
    {
        $path = '/api/v1/servers/'
            .rawurlencode((string) $secondary['server_id'])
            .'/zones';
        $response = $this->requestFor(
            (string) $secondary['api_url'],
            (string) $secondary['api_key'],
        )->post($path, [
            'name' => $zoneName,
            'kind' => 'Secondary',
            'masters' => [(string) $secondary['master_ip']],
            'nameservers' => [],
        ]);

        if ($response->successful()
            || $response->status() === 409
            || str_contains(strtolower($response->body()), 'already exists')) {
            return;
        }

        $response->throw();
    }

    private function requestFor(string $baseUrl, string $apiKey): PendingRequest
    {
        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->withHeaders(['X-API-Key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->connectTimeout(3)
            ->retry([200, 500, 1000], throw: false);
    }

    private function zonesPath(): string
    {
        return '/api/v1/servers/'.rawurlencode((string) config('oneploy.dns.powerdns_server_id', 'localhost')).'/zones';
    }
}
