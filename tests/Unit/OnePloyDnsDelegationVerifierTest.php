<?php

use App\Services\OnePloy\DnsDelegationVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('delegation requires the exact nameserver set from every independent resolver', function () {
    config()->set('oneploy.dns.public_resolvers', [
        'https://dns.google/resolve',
        'https://cloudflare-dns.com/dns-query',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://dns.google/resolve*' => Http::response([
            'Status' => 0,
            'Answer' => [
                ['type' => 2, 'data' => 'NS2.ONEPLOY.DEV.'],
                ['type' => 2, 'data' => 'ns1.oneploy.dev.'],
            ],
        ]),
        'https://cloudflare-dns.com/dns-query*' => Http::response([
            'Status' => 0,
            'Answer' => [
                ['type' => 2, 'data' => 'ns1.oneploy.dev.'],
                ['type' => 2, 'data' => 'old-ns.example.net.'],
            ],
        ]),
    ]);

    $verified = app(DnsDelegationVerifier::class)->isDelegated('Example.com.', [
        'ns1.oneploy.dev',
        'ns2.oneploy.dev',
    ]);

    expect($verified)->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['name'] === 'example.com'
        && $request['type'] === 'NS'
        && $request['do'] === 'true'
        && $request->hasHeader('Accept', 'application/dns-json'));
});

test('delegation refuses resolver configuration without two independent hosts', function () {
    config()->set('oneploy.dns.public_resolvers', [
        'https://dns.google/resolve',
        'https://dns.google/dns-query',
    ]);
    Http::preventStrayRequests();

    expect(app(DnsDelegationVerifier::class)->isDelegated('example.com', [
        'ns1.oneploy.dev',
        'ns2.oneploy.dev',
    ]))->toBeFalse();

    Http::assertNothingSent();
});
