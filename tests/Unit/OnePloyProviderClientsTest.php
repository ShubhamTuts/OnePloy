<?php

use App\Services\OnePloy\ConnectResellerClient;
use App\Services\OnePloy\PowerDnsClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('ConnectReseller availability uses the official v11 ESHOP endpoint', function () {
    config()->set('oneploy.domains.connectreseller_api_url', 'https://api.connectreseller.test/ConnectReseller/ESHOP');
    config()->set('oneploy.domains.connectreseller_api_key', 'registrar-key');
    Http::preventStrayRequests();
    Http::fake([
        '*/checkdomainavailable*' => Http::response([
            'responseMsg' => ['statusCode' => 200, 'message' => 'Available'],
            'responseData' => ['available' => true, 'isPremium' => false],
        ]),
    ]);

    $result = app(ConnectResellerClient::class)->availability('launch.co.in');

    expect($result['available'])->toBeTrue();
    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/checkdomainavailable')
            && $request['APIKey'] === 'registrar-key'
            && $request['websiteName'] === 'launch.co.in';
    });
});

test('PowerDNS creates a native authoritative zone with configured nameservers', function () {
    config()->set('oneploy.dns.powerdns_api_url', 'https://dns-api.oneploy.test');
    config()->set('oneploy.dns.powerdns_api_key', 'dns-key');
    config()->set('oneploy.dns.powerdns_server_id', 'localhost');
    config()->set('oneploy.dns.nameservers', ['ns1.oneploy.dev', 'ns2.oneploy.dev']);
    config()->set('oneploy.dns.dnssec', true);
    Http::preventStrayRequests();
    Http::fake([
        'https://dns-api.oneploy.test/api/v1/servers/localhost/zones' => Http::response([
            'id' => 'example.com.',
            'name' => 'example.com.',
            'kind' => 'Native',
            'dnssec' => true,
        ], 201),
    ]);

    $zone = app(PowerDnsClient::class)->ensureZone('example.com');

    expect($zone['name'])->toBe('example.com.');
    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->hasHeader('X-API-Key', 'dns-key')
            && $request['name'] === 'example.com.'
            && $request['nameservers'] === ['ns1.oneploy.dev.', 'ns2.oneploy.dev.']
            && $request['dnssec'] === true;
    });
});
