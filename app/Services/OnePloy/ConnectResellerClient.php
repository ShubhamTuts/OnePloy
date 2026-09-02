<?php

namespace App\Services\OnePloy;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ConnectResellerClient
{
    public function availability(string $domain): array
    {
        $apiKey = config('oneploy.domains.connectreseller_api_key');
        if (blank($apiKey)) {
            return [
                'domain' => $domain,
                'available' => null,
                'source' => 'unconfigured',
                'message' => 'ConnectReseller API key is not configured. Availability is an EXTERNAL BLOCKER until credentials are added.',
            ];
        }

        $response = Http::timeout(20)->get(rtrim((string) config('oneploy.domains.connectreseller_api_url'), '/').'/checkdomain', [
            'APIKey' => $apiKey,
            'domainName' => $domain,
        ]);

        return [
            'domain' => $domain,
            'available' => $response->json('available', $response->successful()),
            'source' => 'connectreseller',
            'raw_status' => $response->status(),
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
