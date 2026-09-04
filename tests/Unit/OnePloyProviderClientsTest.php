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

test('PowerDNS provisions primary and independent secondary zones before reporting HA configured', function () {
    config()->set('oneploy.dns.powerdns_api_url', 'https://ns1-api.private.test');
    config()->set('oneploy.dns.powerdns_api_key', 'primary-key');
    config()->set('oneploy.dns.powerdns_server_id', 'localhost');
    config()->set('oneploy.dns.primary_site', 'provider-a');
    config()->set('oneploy.dns.require_ha', true);
    config()->set('oneploy.dns.nameservers', ['ns1.oneploy.dev', 'ns2.oneploy.dev']);
    config()->set('oneploy.dns.secondaries', [[
        'api_url' => 'https://ns2-api.private.test',
        'api_key' => 'secondary-key',
        'server_id' => 'localhost',
        'master_ip' => '10.10.0.1',
        'site' => 'provider-b',
    ]]);
    Http::preventStrayRequests();
    Http::fake([
        'https://ns1-api.private.test/api/v1/servers/localhost/zones' => Http::response([
            'name' => 'example.com.',
            'kind' => 'Primary',
            'dnssec' => true,
        ], 201),
        'https://ns2-api.private.test/api/v1/servers/localhost/zones' => Http::response([
            'name' => 'example.com.',
            'kind' => 'Secondary',
        ], 201),
    ]);

    $client = app(PowerDnsClient::class);
    $zone = $client->ensureZone('example.com');

    expect($client->isConfigured())->toBeTrue()
        ->and($client->isHighAvailabilityConfigured())->toBeTrue()
        ->and($zone['kind'])->toBe('Primary');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ns1-api.private.test/api/v1/servers/localhost/zones'
        && $request['kind'] === 'Primary'
        && $request['masters'] === []);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ns2-api.private.test/api/v1/servers/localhost/zones'
        && $request->hasHeader('X-API-Key', 'secondary-key')
        && $request['kind'] === 'Secondary'
        && $request['masters'] === ['10.10.0.1']);
});

test('HA-required DNS stays gated when the secondary is not independently configured', function () {
    config()->set('oneploy.dns.powerdns_api_url', 'https://dns-api.oneploy.test');
    config()->set('oneploy.dns.powerdns_api_key', 'primary-key');
    config()->set('oneploy.dns.primary_site', 'provider-a');
    config()->set('oneploy.dns.require_ha', true);
    config()->set('oneploy.dns.nameservers', ['ns1.oneploy.dev', 'ns2.oneploy.dev']);
    config()->set('oneploy.dns.secondaries', [[
        'api_url' => 'https://dns-api.oneploy.test',
        'api_key' => 'secondary-key',
        'server_id' => 'localhost',
        'master_ip' => '10.10.0.1',
        'site' => 'provider-a',
    ]]);

    $client = app(PowerDnsClient::class);

    expect($client->isHighAvailabilityConfigured())->toBeFalse()
        ->and($client->isConfigured())->toBeFalse();
});

test('HA-required DNS rejects plaintext remote secondary API credentials', function () {
    config()->set('oneploy.dns.powerdns_api_url', 'http://powerdns:8081');
    config()->set('oneploy.dns.powerdns_api_key', 'primary-key');
    config()->set('oneploy.dns.primary_site', 'provider-a');
    config()->set('oneploy.dns.require_ha', true);
    config()->set('oneploy.dns.nameservers', ['ns1.oneploy.dev', 'ns2.oneploy.dev']);
    config()->set('oneploy.dns.secondaries', [[
        'api_url' => 'http://10.10.0.2:8081',
        'api_key' => 'secondary-key',
        'server_id' => 'localhost',
        'master_ip' => '10.10.0.1',
        'site' => 'provider-b',
    ]]);

    expect(app(PowerDnsClient::class)->isHighAvailabilityConfigured())->toBeFalse();
});
