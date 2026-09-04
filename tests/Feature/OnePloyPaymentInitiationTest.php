<?php

use App\Models\InstanceSettings;
use App\Models\OneployCheckoutSession;
use App\Models\OneployPrice;
use App\Models\Team;
use App\Models\User;
use App\Services\OnePloy\CatalogService;
use App\Services\OnePloy\CheckoutService;
use App\Services\OnePloy\RazorpayClient;
use App\Services\OnePloy\StripeCheckoutClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('oneploy.payments.stripe_secret', 'stripe-secret');
    config()->set('oneploy.payments.stripe_webhook_secret', 'stripe-webhook-secret');
    config()->set('oneploy.payments.stripe_base_url', 'https://api.stripe.com');
    config()->set('oneploy.payments.razorpay_key', 'razorpay-public-key');
    config()->set('oneploy.payments.razorpay_secret', 'razorpay-secret');
    config()->set('oneploy.payments.razorpay_webhook_secret', 'razorpay-webhook-secret');
    config()->set('oneploy.payments.razorpay_base_url', 'https://api.razorpay.com');
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

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('payment providers reject plaintext API endpoints', function (string $configKey, string $client) {
    config()->set($configKey, 'http://payments.internal.test');

    expect(app($client)->isConfigured())->toBeFalse();
})->with([
    'Stripe' => ['oneploy.payments.stripe_base_url', StripeCheckoutClient::class],
    'Razorpay' => ['oneploy.payments.razorpay_base_url', RazorpayClient::class],
]);

test('Stripe initiation uses the persisted checkout snapshot and is idempotent', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $checkout = OneployCheckoutSession::query()->firstOrFail();

        return Http::response([
            'id' => 'cs_oneploy_1',
            'status' => 'open',
            'url' => 'https://checkout.stripe.com/c/pay/cs_oneploy_1',
            'amount_total' => $checkout->amount_minor,
            'currency' => strtolower($checkout->currency),
        ], 200);
    });

    $payload = [
        'price_id' => $this->price->id,
        'provider' => 'stripe',
        'idempotency_key' => 'stripe-checkout-one',
        'amount_minor' => 1,
        'currency' => 'INR',
    ];

    $first = $this->postJson('/api/storefront/v1/checkout', $payload)
        ->assertCreated()
        ->assertJsonPath('checkout.provider', 'stripe')
        ->assertJsonPath('checkout.status', 'pending_provider')
        ->assertJsonPath('checkout.approval_url', 'https://checkout.stripe.com/c/pay/cs_oneploy_1')
        ->assertJsonPath('checkout.provider_data.checkout_session_id', 'cs_oneploy_1');

    $second = $this->postJson('/api/storefront/v1/checkout', $payload)
        ->assertCreated();

    expect($second->json('checkout.id'))->toBe($first->json('checkout.id'));

    $checkout = OneployCheckoutSession::query()->where('uuid', $first->json('checkout.id'))->firstOrFail();
    expect($checkout->provider_reference)->toBe('cs_oneploy_1')
        ->and($checkout->amount_minor)->toBe($this->price->amount_minor)
        ->and($checkout->currency)->toBe($this->price->currency)
        ->and($checkout->provider_payload)->toBe([
            'checkout_session_id' => 'cs_oneploy_1',
            'status' => 'open',
        ]);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($checkout): bool {
        return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request->hasHeader('Authorization', 'Bearer stripe-secret')
            && $request->hasHeader('Idempotency-Key', hash('sha256', 'oneploy:stripe:'.$checkout->uuid))
            && $request['client_reference_id'] === $checkout->uuid
            && $request['line_items'][0]['price_data']['unit_amount'] === $checkout->amount_minor
            && $request['line_items'][0]['price_data']['currency'] === strtolower($checkout->currency)
            && $request['metadata']['oneploy_checkout_id'] === $checkout->uuid;
    });
});

test('Razorpay initiation exposes only public checkout data and validates the provider order', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $checkout = OneployCheckoutSession::query()->firstOrFail();

        return Http::response([
            'id' => 'order_oneploy_1',
            'entity' => 'order',
            'amount' => $checkout->amount_minor,
            'amount_paid' => 0,
            'amount_due' => $checkout->amount_minor,
            'currency' => strtoupper($checkout->currency),
            'receipt' => mb_substr('op_'.$checkout->uuid, 0, 40),
            'status' => 'created',
        ], 200);
    });

    $response = $this->postJson('/api/storefront/v1/checkout', [
        'price_id' => $this->price->id,
        'provider' => 'razorpay',
        'idempotency_key' => 'razorpay-checkout-one',
    ])->assertCreated()
        ->assertJsonPath('checkout.provider', 'razorpay')
        ->assertJsonPath('checkout.status', 'pending_provider')
        ->assertJsonPath('checkout.approval_url', null)
        ->assertJsonPath('checkout.provider_data.key_id', 'razorpay-public-key')
        ->assertJsonPath('checkout.provider_data.order_id', 'order_oneploy_1');

    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('razorpay-secret');

    $checkout = OneployCheckoutSession::query()->where('uuid', $response->json('checkout.id'))->firstOrFail();
    expect(json_encode($checkout->provider_payload, JSON_THROW_ON_ERROR))->not->toContain('razorpay-secret');

    Http::assertSent(function (Request $request) use ($checkout): bool {
        return $request->url() === 'https://api.razorpay.com/v1/orders'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('razorpay-public-key:razorpay-secret'))
            && $request['amount'] === $checkout->amount_minor
            && $request['currency'] === strtoupper($checkout->currency)
            && $request['notes']['oneploy_checkout_id'] === $checkout->uuid
            && mb_strlen((string) $request['receipt']) <= 40;
    });
});

test('Razorpay safely reconciles an ambiguous create response by its stable receipt', function () {
    Http::preventStrayRequests();
    $postAttempts = 0;
    Http::fake(function (Request $request) use (&$postAttempts) {
        $checkout = OneployCheckoutSession::query()->firstOrFail();
        $order = [
            'id' => 'order_reconciled',
            'entity' => 'order',
            'amount' => $checkout->amount_minor,
            'amount_paid' => 0,
            'amount_due' => $checkout->amount_minor,
            'currency' => strtoupper($checkout->currency),
            'receipt' => mb_substr('op_'.$checkout->uuid, 0, 40),
            'status' => 'created',
        ];

        if ($request->method() === 'GET') {
            return Http::response(['entity' => 'collection', 'count' => $postAttempts, 'items' => $postAttempts > 0 ? [$order] : []]);
        }

        $postAttempts++;

        return Http::response(['error' => ['description' => 'ambiguous failure']], 500);
    });

    $payload = [
        'price_id' => $this->price->id,
        'provider' => 'razorpay',
        'idempotency_key' => 'razorpay-ambiguous-one',
    ];

    $this->postJson('/api/storefront/v1/checkout', $payload)->assertStatus(502);
    $this->postJson('/api/storefront/v1/checkout', $payload)
        ->assertCreated()
        ->assertJsonPath('checkout.provider_data.order_id', 'order_reconciled');

    expect($postAttempts)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://api.razorpay.com/v1/orders?'));
});

test('payment initiation is team scoped and cannot change an assigned provider', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.stripe.com/*' => Http::response([
            'id' => 'cs_bound_provider',
            'status' => 'open',
            'url' => 'https://checkout.stripe.com/c/pay/cs_bound_provider',
            'amount_total' => $this->price->amount_minor,
            'currency' => strtolower($this->price->currency),
        ]),
    ]);

    $checkout = app(CheckoutService::class)->create(
        ['price_id' => $this->price->id],
        $this->team,
        $this->user->id,
    );

    $otherTeam = Team::factory()->create();
    $otherOwner = User::factory()->create();
    $otherTeam->members()->attach($otherOwner->id, ['role' => 'owner']);
    $this->actingAs($otherOwner);
    session(['currentTeam' => $otherTeam]);

    $this->postJson('/api/storefront/v1/checkout/'.$checkout->uuid.'/payment', ['provider' => 'stripe'])
        ->assertNotFound();
    Http::assertNothingSent();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->postJson('/api/storefront/v1/checkout/'.$checkout->uuid.'/payment', ['provider' => 'stripe'])
        ->assertOk();
    $this->postJson('/api/storefront/v1/checkout/'.$checkout->uuid.'/payment', ['provider' => 'razorpay'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This checkout is already assigned to stripe.');

    Http::assertSentCount(1);
});

test('provider failures are safely recorded and can be retried without leaking secrets', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.stripe.com/*' => Http::response([
            'error' => ['message' => 'declined stripe-secret private upstream diagnostics'],
        ], 500),
    ]);

    $response = $this->postJson('/api/storefront/v1/checkout', [
        'price_id' => $this->price->id,
        'provider' => 'stripe',
        'idempotency_key' => 'stripe-failure-one',
    ])->assertStatus(502)
        ->assertJsonPath('message', 'The payment provider could not start checkout.');

    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('stripe-secret')
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('upstream diagnostics');

    $checkout = OneployCheckoutSession::query()->where('idempotency_key', 'stripe-failure-one')->firstOrFail();
    expect($checkout->status)->toBe('open')
        ->and($checkout->provider)->toBe('stripe')
        ->and($checkout->provider_reference)->toBeNull()
        ->and($checkout->failure_reason)->toBe('The payment provider could not start checkout.');
});

test('a provider response with a different amount cannot open payment checkout', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.stripe.com/*' => Http::response([
            'id' => 'cs_wrong_amount',
            'status' => 'open',
            'url' => 'https://checkout.stripe.com/c/pay/cs_wrong_amount',
            'amount_total' => $this->price->amount_minor + 1,
            'currency' => strtolower($this->price->currency),
        ]),
    ]);

    $this->postJson('/api/storefront/v1/checkout', [
        'price_id' => $this->price->id,
        'provider' => 'stripe',
        'idempotency_key' => 'stripe-mismatch-one',
    ])->assertStatus(502);

    $checkout = OneployCheckoutSession::query()->where('idempotency_key', 'stripe-mismatch-one')->firstOrFail();
    expect($checkout->status)->toBe('open')
        ->and($checkout->provider_reference)->toBeNull()
        ->and($checkout->approval_url)->toBeNull();
});
