<?php

use App\Models\InstanceSettings;
use App\Models\OneployCheckoutSession;
use App\Models\OneployCommerceSubscription;
use App\Models\OneployInvoice;
use App\Models\OneployOrder;
use App\Models\OneployPayment;
use App\Models\OneployPrice;
use App\Models\OneployProduct;
use App\Models\Team;
use App\Models\User;
use App\Services\OnePloy\CatalogService;
use App\Services\OnePloy\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('oneploy.payments.paypal_client_id', 'paypal-client');
    config()->set('oneploy.payments.paypal_secret', 'paypal-secret');
    config()->set('oneploy.payments.paypal_webhook_id', 'WH-ONEPLOY');
    config()->set('oneploy.payments.paypal_base_url', 'https://api-m.sandbox.paypal.com');
    Cache::flush();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    app(CatalogService::class)->seed();
    $this->price = OneployPrice::query()
        ->where('currency', 'USD')
        ->where('interval', 'monthly')
        ->whereHas('planVersion.plan.product', fn ($query) => $query->where('family', 'app_hosting'))
        ->firstOrFail();
});

test('an admin can pay through PayPal and only a verified server capture activates the order', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/v1/oauth2/token')) {
            return Http::response(['access_token' => 'access-token', 'expires_in' => 3600]);
        }

        if (str_ends_with($request->url(), '/v2/checkout/orders')) {
            return Http::response([
                'id' => 'PAYPAL-ORDER-1',
                'status' => 'CREATED',
                'links' => [[
                    'rel' => 'payer-action',
                    'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-1',
                ]],
            ], 201);
        }

        if (str_ends_with($request->url(), '/v2/checkout/orders/PAYPAL-ORDER-1/capture')) {
            $checkout = OneployCheckoutSession::query()->where('provider_reference', 'PAYPAL-ORDER-1')->firstOrFail();

            return Http::response(payPalCompletedOrder($checkout, 'PAYPAL-ORDER-1', 'CAPTURE-1'));
        }

        return Http::response([], 404);
    });

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $response = $this->postJson('/api/storefront/v1/checkout', [
        'price_id' => $this->price->id,
        'idempotency_key' => 'checkout-from-storefront',
    ])->assertCreated()
        ->assertJsonPath('checkout.provider', 'paypal')
        ->assertJsonPath('checkout.status', 'pending_provider')
        ->assertJsonPath('checkout.approval_url', 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-1');

    $checkout = OneployCheckoutSession::query()->where('uuid', $response->json('checkout.id'))->firstOrFail();
    expect($checkout->status)->toBe('pending_provider')
        ->and(OneployOrder::query()->count())->toBe(0);

    $this->get('/billing/paypal/return?token=PAYPAL-ORDER-1')
        ->assertRedirect(route('oneploy.billing'));

    expect($checkout->fresh()->status)->toBe('paid')
        ->and(OneployOrder::query()->count())->toBe(1)
        ->and(OneployInvoice::query()->where('status', 'paid')->count())->toBe(1)
        ->and(OneployPayment::query()->where('provider_reference', 'CAPTURE-1')->count())->toBe(1)
        ->and(OneployCommerceSubscription::query()->where('team_id', $this->team->id)->count())->toBe(1);

    Http::assertSent(function (Request $request) use ($checkout): bool {
        return str_ends_with($request->url(), '/v2/checkout/orders')
            && $request['purchase_units'][0]['custom_id'] === $checkout->uuid
            && $request['purchase_units'][0]['amount']['value'] === number_format($checkout->amount_minor / 100, 2, '.', '');
    });
});

test('a verified PayPal capture webhook activates a matching checkout idempotently', function () {
    $checkout = app(CheckoutService::class)->create(
        ['price_id' => $this->price->id],
        $this->team,
        $this->user->id,
    );
    $checkout->update([
        'provider' => 'paypal',
        'provider_reference' => 'PAYPAL-ORDER-2',
        'status' => 'pending_provider',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600]),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);

    $payload = json_encode([
        'id' => 'WH-EVENT-1',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'CAPTURE-2',
            'custom_id' => $checkout->uuid,
            'status' => 'COMPLETED',
            'amount' => [
                'value' => number_format($checkout->amount_minor / 100, 2, '.', ''),
                'currency_code' => $checkout->currency,
            ],
            'supplementary_data' => ['related_ids' => ['order_id' => 'PAYPAL-ORDER-2']],
        ],
    ], JSON_THROW_ON_ERROR);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
        'HTTP_PAYPAL_CERT_URL' => 'https://api-m.paypal.com/cert.pem',
        'HTTP_PAYPAL_TRANSMISSION_ID' => 'transmission-1',
        'HTTP_PAYPAL_TRANSMISSION_SIG' => 'signature-1',
        'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String(),
    ];

    $this->call('POST', '/webhooks/payments/paypal/oneploy', [], [], [], $server, $payload)->assertOk();
    $this->call('POST', '/webhooks/payments/paypal/oneploy', [], [], [], $server, $payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'duplicate' => true]);

    expect($checkout->fresh()->status)->toBe('paid')
        ->and(OneployPayment::query()->where('provider_reference', 'CAPTURE-2')->count())->toBe(1);

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/v1/notifications/verify-webhook-signature')
            && $request['webhook_id'] === 'WH-ONEPLOY'
            && $request['transmission_id'] === 'transmission-1';
    });
});

test('members cannot start or inspect billing checkout', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $checkout = app(CheckoutService::class)->create(['price_id' => $this->price->id], $this->team, $this->user->id);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->postJson('/api/storefront/v1/checkout', ['price_id' => $this->price->id])->assertForbidden();
    $this->getJson('/api/storefront/v1/checkout/'.$checkout->uuid)->assertForbidden();
    $this->get(route('oneploy.billing'))->assertForbidden();
});

test('purchases for separate product families keep separate subscriptions', function () {
    $appCheckout = app(CheckoutService::class)->create(['price_id' => $this->price->id], $this->team, $this->user->id);
    app(CheckoutService::class)->markPaid($appCheckout, 'paypal', 'CAPTURE-APP');

    $wordpressProduct = OneployProduct::query()->where('family', 'wordpress')->firstOrFail();
    $wordpressProduct->update(['is_active' => true]);
    $wordpressPrice = OneployPrice::query()
        ->where('currency', 'USD')
        ->where('interval', 'monthly')
        ->whereHas('planVersion.plan.product', fn ($query) => $query->whereKey($wordpressProduct->id))
        ->firstOrFail();
    $wordpressCheckout = app(CheckoutService::class)->create(['price_id' => $wordpressPrice->id], $this->team, $this->user->id);
    app(CheckoutService::class)->markPaid($wordpressCheckout, 'paypal', 'CAPTURE-WORDPRESS');

    expect(OneployCommerceSubscription::query()->where('team_id', $this->team->id)->count())->toBe(2)
        ->and(OneployCommerceSubscription::query()->where('team_id', $this->team->id)->pluck('product_id')->unique()->count())->toBe(2);
});

/** @return array<string, mixed> */
function payPalCompletedOrder(OneployCheckoutSession $checkout, string $orderId, string $captureId): array
{
    return [
        'id' => $orderId,
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'reference_id' => $checkout->uuid,
            'custom_id' => $checkout->uuid,
            'amount' => [
                'currency_code' => $checkout->currency,
                'value' => number_format($checkout->amount_minor / 100, 2, '.', ''),
            ],
            'payments' => ['captures' => [[
                'id' => $captureId,
                'status' => 'COMPLETED',
                'amount' => [
                    'currency_code' => $checkout->currency,
                    'value' => number_format($checkout->amount_minor / 100, 2, '.', ''),
                ],
            ]]],
        ]],
    ];
}
