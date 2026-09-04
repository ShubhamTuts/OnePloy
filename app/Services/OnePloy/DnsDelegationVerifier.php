<?php

namespace App\Services\OnePloy;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class DnsDelegationVerifier
{
    /**
     * Confirm the exact delegated nameserver set through at least two
     * independently operated public recursive resolvers.
     *
     * @param  list<string>  $expectedNameservers
     */
    public function isDelegated(string $domain, array $expectedNameservers): bool
    {
        $domain = rtrim(strtolower(trim($domain)), '.');
        $expected = $this->normalizeNameservers($expectedNameservers);
        $resolvers = $this->trustedResolvers();

        if ($domain === '' || count($expected) < 2 || count($resolvers) < 2) {
            return false;
        }

        try {
            $responses = Http::pool(fn (Pool $pool): array => collect($resolvers)
                ->mapWithKeys(fn (string $resolver, int $index): array => [
                    'resolver-'.$index => $pool
                        ->as('resolver-'.$index)
                        ->accept('application/dns-json')
                        ->connectTimeout(2)
                        ->timeout(5)
                        ->retry([100, 300], throw: false)
                        ->get($resolver, [
                            'name' => $domain,
                            'type' => 'NS',
                            'do' => 'true',
                        ]),
                ])
                ->all());
        } catch (Throwable) {
            return false;
        }

        foreach ($responses as $response) {
            if (! $response instanceof Response
                || ! $response->successful()
                || (int) $response->json('Status', -1) !== 0) {
                return false;
            }

            $answers = collect($response->json('Answer', []))
                ->filter(fn (mixed $answer): bool => is_array($answer) && (int) ($answer['type'] ?? 0) === 2)
                ->pluck('data')
                ->filter(fn (mixed $nameserver): bool => is_string($nameserver))
                ->all();

            if ($this->normalizeNameservers($answers) !== $expected) {
                return false;
            }
        }

        return count($responses) === count($resolvers);
    }

    /** @return list<string> */
    private function trustedResolvers(): array
    {
        $resolvers = config('oneploy.dns.public_resolvers', []);

        if (! is_array($resolvers)) {
            return [];
        }

        $trusted = collect($resolvers)
            ->filter(function (mixed $resolver): bool {
                if (! is_string($resolver) || ! str_starts_with($resolver, 'https://')) {
                    return false;
                }

                return filled(parse_url($resolver, PHP_URL_HOST));
            })
            ->unique(fn (string $resolver): string => strtolower((string) parse_url($resolver, PHP_URL_HOST)))
            ->values()
            ->all();

        return count($trusted) >= 2 ? $trusted : [];
    }

    /**
     * @param  array<int, mixed>  $nameservers
     * @return list<string>
     */
    private function normalizeNameservers(array $nameservers): array
    {
        return collect($nameservers)
            ->filter(fn (mixed $nameserver): bool => is_string($nameserver))
            ->map(fn (string $nameserver): string => rtrim(strtolower(trim($nameserver)), '.'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
