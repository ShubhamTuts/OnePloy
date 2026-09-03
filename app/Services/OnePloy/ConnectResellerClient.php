<?php

namespace App\Services\OnePloy;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ConnectResellerClient
{
    public function isConfigured(): bool
    {
        return filled(config('oneploy.domains.connectreseller_api_url'))
            && filled(config('oneploy.domains.connectreseller_api_key'));
    }

    public function availability(string $domain): array
    {
        if (! $this->isConfigured()) {
            return [
                'domain' => $domain,
                'available' => null,
                'source' => 'unconfigured',
                'message' => 'ConnectReseller API key is not configured. Availability is an EXTERNAL BLOCKER until credentials are added.',
            ];
        }

        [$websiteName, $extension] = explode('.', strtolower($domain), 2);
        $response = Http::baseUrl(rtrim((string) config('oneploy.domains.connectreseller_api_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([200, 500, 1000], throw: false)
            ->post('/domains/checkDomainAvailability', [
                'apiKey' => (string) config('oneploy.domains.connectreseller_api_key'),
                'websiteName' => $websiteName,
                'extension' => '.'.$extension,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('ConnectReseller availability request failed with HTTP '.$response->status().'.');
        }

        $available = data_get($response->json(), 'responseData.available')
            ?? data_get($response->json(), 'responseData.isAvailable')
            ?? data_get($response->json(), 'available');

        return [
            'domain' => $domain,
            'available' => is_bool($available) ? $available : null,
            'source' => 'connectreseller',
            'raw_status' => $response->status(),
            'message' => is_string($response->json('message')) ? $response->json('message') : null,
        ];
    }

    public function suggest(string $query): array
    {
        return [
            'query' => $query,
            'suggestions' => [
                Str::slug($query).'.com',
                Str::slug($query).'.net',
                Str::slug($query).'.in',
            ],
        ];
    }
}
