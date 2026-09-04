<?php

use App\Jobs\OnePloy\ProvisionDomainJob;
use App\Jobs\OnePloy\VerifyDomainDnsJob;
use App\Models\InstanceSettings;
use App\Models\OneployDnsZone;
use App\Models\OneployDomain;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TransactionalEmails\DomainRegistered;
use App\Services\OnePloy\CheckoutService;
use App\Services\OnePloy\ConnectResellerClient;
use App\Services\OnePloy\DnsDelegationVerifier;
use App\Services\OnePloy\PowerDnsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('oneploy.payments.paypal_client_id', 'paypal-client');
    config()->set('oneploy.payments.paypal_secret', 'paypal-secret');
    config()->set('oneploy.payments.paypal_webhook_id', 'WH-DOMAINS');
    config()->set('oneploy.payments.paypal_base_url', 'https://api-m.sandbox.paypal.com');
    config()->set('oneploy.domains.connectreseller_api_url', 'https://api.connectreseller.test/ConnectReseller/ESHOP');
    config()->set('oneploy.domains.connectreseller_api_key', 'registrar-secret');
    config()->set('oneploy.domains.retail_prices', [
        'com' => ['USD' => 1299],
        'net' => ['USD' => 1499],
    ]);
    config()->set('oneploy.domains.default_currency', 'USD');
    config()->set('oneploy.dns.powerdns_api_url', 'http://powerdns:8081');
    config()->set('oneploy.dns.powerdns_api_key', 'powerdns-secret');
    config()->set('oneploy.dns.powerdns_server_id', 'localhost');
    config()->set('oneploy.dns.nameservers', ['ns1.oneploy.dev', 'ns2.oneploy.dev']);
    config()->set('oneploy.dns.public_resolvers', [
        'https://dns.google/resolve',
        'https://cloudflare-dns.com/dns-query',
    ]);
    Cache::flush();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('domain checkout encrypts contacts and verified payment provisions registrar dns and email', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/v1/oauth2/token') => Http::response(['access_token' => 'access-token', 'expires_in' => 3600]),
            str_ends_with($path, '/v2/checkout/orders') => Http::response([
                'id' => 'PAYPAL-DOMAIN-1',
                'status' => 'CREATED',
                'links' => [[
                    'rel' => 'payer-action',
                    'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-DOMAIN-1',
                ]],
            ], 201),
            str_ends_with($path, '/checkdomainavailable') => Http::response([
                'responseMsg' => ['statusCode' => 200, 'message' => 'Domain is available'],
                'responseData' => ['available' => true, 'isPremium' => false],
            ]),
            str_ends_with($path, '/ViewDomain') => Http::response([
                'responseMsg' => ['statusCode' => 404, 'message' => 'Domain not found'],
            ]),
            str_ends_with($path, '/ViewClient') => Http::response([
                'responseMsg' => ['statusCode' => 400, 'message' => 'Client not found'],
            ]),
            str_ends_with($path, '/AddClient') => Http::response([
                'responseMsg' => ['statusCode' => 200, 'message' => 'Client added'],
                'responseData' => ['clientId' => 73],
            ]),
            str_ends_with($path, '/domainorder') => Http::response([
                'responseMsg' => ['statusCode' => 200, 'message' => 'Domain registered'],
                'responseData' => [
                    'orderId' => 9001,
                    'name' => 'launch-oneploy.com',
                    'creationDate' => '2026-09-03',
                    'expiryDate' => '2027-09-03',
                ],
            ]),
            str_ends_with($path, '/api/v1/servers/localhost/zones') => Http::response([
                'name' => 'launch-oneploy.com.',
                'dnssec' => true,
                'rrsets' => [],
            ], 201),
            default => Http::response([], 404),
        };
    });

    $response = $this->postJson('/api/storefront/v1/domains/checkout', oneployDomainPayload('launch-oneploy.com'))
        ->assertCreated()
        ->assertJsonPath('domain.status', 'pending_payment')
        ->assertJsonPath('checkout.status', 'pending_provider')
        ->assertJsonPath('checkout.amount_minor', 1299)
        ->assertJsonPath('checkout.approval_url', 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-DOMAIN-1');

    $domain = OneployDomain::query()->where('uuid', $response->json('domain.id'))->firstOrFail();
    $rawContact = DB::table('oneploy_domains')->where('id', $domain->id)->value('contact_payload');
    expect($rawContact)->not->toContain('owner@example.com')
        ->and($domain->contact_payload['email'])->toBe('owner@example.com')
        ->and($domain->contacts)->toBeNull();

    Queue::fake();
    app(CheckoutService::class)->markPaid($domain->checkoutSession, 'paypal', 'CAPTURE-DOMAIN-1');
    expect($domain->fresh()->status)->toBe('pending_registration');
    Queue::assertPushed(ProvisionDomainJob::class, fn (ProvisionDomainJob $job): bool => $job->domainId === $domain->id);

    Notification::fake();
    (new ProvisionDomainJob($domain->id))->handle(
        app(ConnectResellerClient::class),
        app(PowerDnsClient::class),
    );

    $domain = $domain->fresh();
    expect($domain->status)->toBe('dns_pending')
        ->and($domain->provider_reference)->toBe('9001')
        ->and($domain->expires_at?->toDateString())->toBe('2027-09-03')
        ->and(OneployDnsZone::query()->where('domain_id', $domain->id)->where('status', 'pending_delegation')->exists())->toBeTrue();
    Queue::assertPushed(VerifyDomainDnsJob::class, fn (VerifyDomainDnsJob $job): bool => $job->domainId === $domain->id);
    Notification::assertSentTo($this->user, DomainRegistered::class);
    $this->getJson('/api/storefront/v1/domains/'.$domain->uuid)
        ->assertOk()
        ->assertJsonPath('status', 'dns_pending')
        ->assertJsonPath('dns_active', false)
        ->assertJsonPath('action_required', 'Registration succeeded; waiting for public nameserver delegation verification.');

    $verifier = Mockery::mock(DnsDelegationVerifier::class);
    $verifier->shouldReceive('isDelegated')
        ->once()
        ->with('launch-oneploy.com', ['ns1.oneploy.dev', 'ns2.oneploy.dev'])
        ->andReturnTrue();
    (new VerifyDomainDnsJob($domain->id))->handle($verifier);

    expect($domain->fresh()->status)->toBe('active')
        ->and($domain->dnsZone()->firstOrFail()->status)->toBe('active');

    Http::assertSent(function (Request $request): bool {
        return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/AddClient')
            && $request['APIKey'] === 'registrar-secret'
            && $request['UserName'] === 'owner@example.com'
            && $request['Address1'] === '42 Launch Street'
            && $request['PhoneNo_cc'] === '91';
    });
    Http::assertSent(function (Request $request): bool {
        return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/domainorder')
            && $request['WebsiteName'] === 'launch-oneploy.com'
            && $request['Id'] === 73
            && $request['ns1'] === 'ns1.oneploy.dev'
            && $request['isEnablePremium'] === 0;
    });
});

test('an uncertain registrar purchase is not retried and moves to manual review', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/v1/oauth2/token') => Http::response(['access_token' => 'access-token', 'expires_in' => 3600]),
            str_ends_with($path, '/v2/checkout/orders') => Http::response([
                'id' => 'PAYPAL-DOMAIN-2',
                'status' => 'CREATED',
                'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/domain-2']],
            ], 201),
            str_ends_with($path, '/checkdomainavailable') => Http::response([
                'responseMsg' => ['statusCode' => 200],
                'responseData' => ['available' => true],
            ]),
            str_ends_with($path, '/ViewDomain') => Http::response([
                'responseMsg' => ['statusCode' => 404],
            ]),
            str_ends_with($path, '/ViewClient') => Http::response([
                'responseMsg' => ['statusCode' => 200],
                'responseData' => ['clientId' => 88],
            ]),
            str_ends_with($path, '/domainorder') => Http::response([], 504),
            default => Http::response([], 404),
        };
    });

    $response = $this->postJson('/api/storefront/v1/domains/checkout', oneployDomainPayload('manual-review.net'))
        ->assertCreated();
    $domain = OneployDomain::query()->where('uuid', $response->json('domain.id'))->firstOrFail();

    Queue::fake();
    app(CheckoutService::class)->markPaid($domain->checkoutSession, 'paypal', 'CAPTURE-DOMAIN-2');
    (new ProvisionDomainJob($domain->id))->handle(
        app(ConnectResellerClient::class),
        app(PowerDnsClient::class),
    );

    expect($domain->fresh()->status)->toBe('manual_review')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/domainorder')))->toHaveCount(1);
});

test('domain status and purchase are admin-only and tenant scoped', function () {
    $otherTeam = Team::factory()->create();
    $otherDomain = OneployDomain::create([
        'team_id' => $otherTeam->id,
        'name' => 'private-other-team.com',
        'status' => 'registered',
    ]);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->postJson('/api/storefront/v1/domains/checkout', oneployDomainPayload('blocked-member.com'))->assertForbidden();
    $this->getJson('/api/storefront/v1/domains/'.$otherDomain->uuid)->assertForbidden();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->getJson('/api/storefront/v1/domains/'.$otherDomain->uuid)->assertNotFound();
});

test('domain checkout is blocked before payment when required HA DNS is incomplete', function () {
    config()->set('oneploy.dns.require_ha', true);
    config()->set('oneploy.dns.primary_site', 'primary-vps');
    config()->set('oneploy.dns.secondaries', []);
    Http::preventStrayRequests();

    $this->postJson('/api/storefront/v1/domains/checkout', oneployDomainPayload('ha-required.com'))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Highly available authoritative DNS must be configured before domain checkout is enabled.');

    Http::assertNothingSent();
});

/** @return array<string, mixed> */
function oneployDomainPayload(string $domain): array
{
    return [
        'domain' => $domain,
        'currency' => 'USD',
        'years' => 1,
        'privacy' => true,
        'idempotency_key' => 'domain-'.$domain,
        'registrant' => [
            'name' => 'OnePloy Owner',
            'email' => 'owner@example.com',
            'company' => 'OnePloy Labs',
            'address' => '42 Launch Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'postal_code' => '560001',
            'phone_country_code' => '+91',
            'phone' => '9876543210',
            'consent' => true,
        ],
    ];
}
