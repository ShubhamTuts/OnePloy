<?php

namespace App\Services\OnePloy;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PowerDnsClient
{
    public function isConfigured(): bool
    {
        return filled(config('oneploy.dns.powerdns_api_url'))
            && filled(config('oneploy.dns.powerdns_api_key'))
            && count(config('oneploy.dns.nameservers', [])) >= 2;
    }

    /** @return array<string, mixed> */
    public function ensureZone(string $domain): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('PowerDNS API and at least two nameservers must be configured.');
        }

        $zoneName = rtrim(strtolower($domain), '.').'.';
        $response = $this->request()->post($this->zonesPath(), [
            'name' => $zoneName,
            'kind' => 'Native',
            'masters' => [],
            'nameservers' => collect(config('oneploy.dns.nameservers'))
                ->map(fn (string $nameserver): string => rtrim(strtolower($nameserver), '.').'.')
                ->values()
                ->all(),
            'dnssec' => (bool) config('oneploy.dns.dnssec', false),
            'api_rectify' => true,
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        if ($response->status() === 409 || str_contains(strtolower($response->body()), 'already exists')) {
            return $this->zone($domain);
        }

        $response->throw();

        return [];
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

    private function zonesPath(): string
    {
        return '/api/v1/servers/'.rawurlencode((string) config('oneploy.dns.powerdns_server_id', 'localhost')).'/zones';
    }
}
