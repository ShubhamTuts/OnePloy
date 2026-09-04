<?php

use App\Models\InstanceSettings;
use App\Models\OneployCheckoutSession;
use App\Models\OneployInvoice;
use App\Models\OneployOrder;
use App\Models\OneployPayment;
use App\Models\OneployPaymentWebhookEvent;
use App\Models\OneployPrice;
use App\Models\Team;
use App\Models\User;
use App\Services\OnePloy\CatalogService;
use App\Services\OnePloy\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('oneploy.payments.stripe_webhook_secret', 'whsec_oneploy_test');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    app(CatalogService::class)->seed();
    $this->price = OneployPrice::query()
        ->where('currency', 'USD')
        ->where('interval', 'monthly')
        ->whereHas('planVersion.plan.product', fn ($query) => $query->where('family', 'app_hosting'))
        ->firstOrFail();
    $this->checkout = app(CheckoutService::class)->create(
        ['price_id' => $this->price->id],
        $this->team,
        $this->user->id,
    );
    $this->checkout->update(['provider' => 'stripe']);
});

function postSignedOnePloyStripeEvent(
    TestCase $test,
    OneployCheckoutSession $checkout,
    int $amountMinor,
    string $eventId = 'evt_oneploy_paid',
): TestResponse {
    $payload = json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_oneploy',
                'object' => 'checkout.session',
                'client_reference_id' => $checkout->uuid,
                'payment_intent' => 'pi_oneploy',
                'payment_status' => 'paid',
                'amount_total' => $amountMinor,
                'currency' => strtolower($checkout->currency),
                'customer_details' => ['email' => 'private@example.com'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = 't='.$timestamp.',v1='.hash_hmac(
        'sha256',
        $timestamp.'.'.$payload,
        (string) config('oneploy.payments.stripe_webhook_secret'),
    );

    return $test->call(
        'POST',
        '/webhooks/payments/stripe/oneploy',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ],
        $payload,
    );
}

test('a signed matching Stripe success creates one immutable commerce lifecycle', function () {
    postSignedOnePloyStripeEvent($this, $this->checkout, $this->checkout->amount_minor)
        ->assertOk();
    postSignedOnePloyStripeEvent($this, $this->checkout, $this->checkout->amount_minor)
        ->assertOk()
        ->assertJson(['ok' => true, 'duplicate' => true]);

    expect($this->checkout->fresh()->status)->toBe('paid')
        ->and(OneployOrder::query()->count())->toBe(1)
        ->and(OneployInvoice::query()->where('status', 'paid')->count())->toBe(1)
        ->and(OneployPayment::query()->where('status', 'succeeded')->count())->toBe(1)
        ->and(OneployPaymentWebhookEvent::query()->firstOrFail()->payload)
        ->not->toHaveKey('customer_details');
});

test('a forged signature and a mismatched amount cannot activate checkout', function () {
    $payload = json_encode(['id' => 'evt_forged'], JSON_THROW_ON_ERROR);
    $this->call(
        'POST',
        '/webhooks/payments/stripe/oneploy',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 'forged'],
        $payload,
    )->assertBadRequest();

    postSignedOnePloyStripeEvent($this, $this->checkout, $this->checkout->amount_minor + 1, 'evt_wrong_amount')
        ->assertUnprocessable();

    expect($this->checkout->fresh()->status)->toBe('open')
        ->and(OneployOrder::query()->count())->toBe(0)
        ->and(OneployInvoice::query()->count())->toBe(0)
        ->and(OneployPayment::query()->count())->toBe(0);
});

test('a verified webhook from a different provider cannot activate checkout', function () {
    $this->checkout->update(['provider' => 'paypal']);

    postSignedOnePloyStripeEvent($this, $this->checkout, $this->checkout->amount_minor, 'evt_wrong_provider')
        ->assertUnprocessable()
        ->assertJson(['error' => 'payment provider does not match checkout']);

    expect($this->checkout->fresh()->status)->toBe('open')
        ->and(OneployPaymentWebhookEvent::query()->where('provider_event_id', 'evt_wrong_provider')->firstOrFail()->status)
        ->toBe('rejected')
        ->and(OneployOrder::query()->count())->toBe(0)
        ->and(OneployInvoice::query()->count())->toBe(0)
        ->and(OneployPayment::query()->count())->toBe(0);
});

test('checkout status is authenticated and scoped to the current team', function () {
    $otherTeam = Team::factory()->create();
    $otherCheckout = app(CheckoutService::class)->create(
        ['price_id' => $this->price->id],
        $otherTeam,
    );

    $this->getJson('/api/storefront/v1/checkout/'.$this->checkout->uuid)
        ->assertUnauthorized();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->getJson('/api/storefront/v1/checkout/'.$this->checkout->uuid)
        ->assertOk();
    $this->getJson('/api/storefront/v1/checkout/'.$otherCheckout->uuid)
        ->assertNotFound();
});
