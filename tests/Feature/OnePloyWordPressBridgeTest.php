<?php

use App\Livewire\OnePloy\Domains;
use App\Livewire\OnePloy\MarketingCheckout;
use App\Models\InstanceSettings;
use App\Models\OneployCheckoutSession;
use App\Models\OneployPrice;
use App\Models\Team;
use App\Models\User;
use App\Services\OnePloy\CatalogService;
use App\Services\OnePloy\WordPressMarketingHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Locked;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('oneploy.wordpress_bridge.secret', 'wordpress-bridge-test-secret');
    config()->set('oneploy.wordpress_bridge.marketing_url', 'https://www.oneploy.test');
    config()->set('oneploy.wordpress_bridge.ttl_seconds', 900);
    config()->set('oneploy.payments.stripe_secret', 'stripe-secret');
    config()->set('oneploy.payments.stripe_webhook_secret', 'stripe-webhook-secret');
    config()->set('oneploy.payments.stripe_base_url', 'https://api.stripe.com');
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);

    app(CatalogService::class)->seed();
    $this->price = OneployPrice::query()
        ->where('currency', 'USD')
        ->where('interval', 'monthly')
        ->whereHas('planVersion.plan.product', fn ($query) => $query->where('family', 'app_hosting'))
        ->firstOrFail();
});

test('a valid WordPress handoff is stored server side and sends guests through login', function () {
    $response = $this->get(wordpressHandoffUrl($this->price->id));

    $response->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', route('oneploy.marketing-checkout.confirm'))
        ->assertSessionHas('oneploy.marketing_checkout.price_id', $this->price->id)
        ->assertSessionHas('oneploy.marketing_checkout.provider', 'stripe')
        ->assertSessionMissing('oneploy.marketing_checkout.signature');
});

test('an authenticated team owner reaches the hosted checkout confirmation', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $this->get(wordpressHandoffUrl($this->price->id))
        ->assertRedirect(route('oneploy.marketing-checkout.confirm'));

    $this->get(route('oneploy.marketing-checkout.confirm'))
        ->assertSuccessful()
        ->assertSee($this->price->planVersion->plan->name)
        ->assertSee('Continue to secure payment');
});

test('registration resumes an intended WordPress checkout for a new customer', function () {
    instanceSettings()->update(['is_registration_enabled' => true]);
    session([
        'url.intended' => route('oneploy.marketing-checkout.confirm'),
        'oneploy.marketing_checkout' => wordpressHandoffPayload($this->price->id),
    ]);

    $this->post('/register', [
        'name' => 'WordPress Customer',
        'email' => 'wordpress-customer@example.com',
        'password' => 'Secure-WordPress-Password-123!',
        'password_confirmation' => 'Secure-WordPress-Password-123!',
    ])->assertRedirect(route('oneploy.marketing-checkout.confirm'));

    $this->assertAuthenticated();
    $this->get(route('oneploy.marketing-checkout.confirm'))
        ->assertSuccessful()
        ->assertSee($this->price->planVersion->plan->name);
});

test('marketing payment and return routes remain reachable before onboarding', function () {
    expect(allowedPathsForBoardingAccounts())->toContain(
        'marketing/checkout/confirm',
        'billing',
        'billing/paypal/return',
        'billing/paypal/cancel',
    );
});

test('cloud registration preserves WordPress checkout through email verification', function () {
    config()->set('constants.coolify.self_hosted', false);
    instanceSettings()->update(['is_registration_enabled' => true]);
    Queue::fake();
    session([
        'url.intended' => route('oneploy.marketing-checkout.confirm'),
        'oneploy.marketing_checkout' => wordpressHandoffPayload($this->price->id),
    ]);

    $this->post('/register', [
        'name' => 'Cloud WordPress Customer',
        'email' => 'cloud-wordpress-customer@example.com',
        'password' => 'Secure-Cloud-WordPress-Password-123!',
        'password_confirmation' => 'Secure-Cloud-WordPress-Password-123!',
    ])->assertRedirect(route('verify.email'))
        ->assertSessionHas('url.intended', route('oneploy.marketing-checkout.confirm'));

    $user = User::query()->where('email', 'cloud-wordpress-customer@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute('verify.verify', now()->addMinutes(30), [
        'id' => $user->id,
        'hash' => hash('sha256', $user->getEmailForVerification()),
    ]);

    $this->get($verificationUrl)
        ->assertRedirect(route('oneploy.marketing-checkout.confirm'));
});

test('tampered expired and foreign-return WordPress handoffs fail closed', function (array $overrides) {
    $url = wordpressHandoffUrl($this->price->id, $overrides);

    $this->get($url)->assertForbidden()
        ->assertSessionMissing('oneploy.marketing_checkout');
})->with([
    'tampered price' => [['tamper_price_after_signing' => true]],
    'expired signature' => [['issued_at' => now()->subMinutes(20)->timestamp]],
    'unknown signing key' => [['key_id' => 'retired-key']],
    'unexpected source' => [['source' => 'external']],
    'foreign return URL' => [['return_url' => 'https://attacker.test/finished']],
]);

test('only team administrators may confirm a WordPress checkout', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session([
        'currentTeam' => $this->team,
        'oneploy.marketing_checkout' => wordpressHandoffPayload($this->price->id),
    ]);

    Livewire::test(MarketingCheckout::class)->assertForbidden();
});

test('signed WordPress handoff properties cannot be changed by the browser', function (string $property) {
    $reflection = new ReflectionProperty(MarketingCheckout::class, $property);

    expect($reflection->getAttributes(Locked::class))->not->toBeEmpty();
})->with(['priceId', 'returnUrl', 'attribution']);

test('an unavailable signed provider falls back to a configured provider', function () {
    $this->actingAs($this->owner);
    session([
        'currentTeam' => $this->team,
        'oneploy.marketing_checkout' => wordpressHandoffPayload($this->price->id, ['provider' => 'paypal']),
    ]);

    Livewire::test(MarketingCheckout::class)->assertSet('provider', 'stripe');
});

test('an accepted handoff cannot start checkout after its signed lifetime', function () {
    $this->actingAs($this->owner);
    session([
        'currentTeam' => $this->team,
        'oneploy.marketing_checkout' => wordpressHandoffPayload($this->price->id),
    ]);

    $component = Livewire::test(MarketingCheckout::class);
    $this->travel(16)->minutes();

    $component->call('startCheckout')->assertForbidden();
});

test('a handoff nonce is atomically reserved to its first team and user', function () {
    $payload = wordpressHandoffPayload($this->price->id);
    $otherTeam = Team::factory()->create();
    $otherOwner = User::factory()->create();
    $otherTeam->members()->attach($otherOwner->id, ['role' => 'owner']);
    $handoff = app(WordPressMarketingHandoff::class);

    expect($handoff->reserve($payload, $this->team->id, $this->owner->id))->toBeTrue()
        ->and($handoff->reserve($payload, $this->team->id, $this->owner->id))->toBeTrue()
        ->and($handoff->reserve($payload, $otherTeam->id, $otherOwner->id))->toBeFalse();
});

test('the hosted checkout uses the authoritative price and redirects to the provider', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $checkout = OneployCheckoutSession::query()->firstOrFail();

        return Http::response([
            'id' => 'cs_wordpress_bridge',
            'status' => 'open',
            'url' => 'https://checkout.stripe.com/pay/cs_wordpress_bridge',
            'amount_total' => $checkout->amount_minor,
            'currency' => strtolower($checkout->currency),
        ]);
    });
    $this->actingAs($this->owner);
    session([
        'currentTeam' => $this->team,
        'oneploy.marketing_checkout' => wordpressHandoffPayload($this->price->id),
    ]);

    Livewire::test(MarketingCheckout::class)
        ->set('provider', 'stripe')
        ->call('startCheckout')
        ->assertRedirect('https://checkout.stripe.com/pay/cs_wordpress_bridge');

    $checkout = OneployCheckoutSession::query()->firstOrFail();
    expect(data_get($checkout->attribution, 'source'))->toBe('wordpress')
        ->and($checkout->amount_minor)->toBe($this->price->amount_minor)
        ->and($checkout->provider)->toBe('stripe');
});

test('the domains page accepts a validated marketing-domain prefill', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::withQueryParams(['domain' => 'Example.COM'])
        ->test(Domains::class)
        ->assertSet('query', 'example.com');
});

/** @return array<string, int|string> */
function wordpressHandoffPayload(int $priceId, array $overrides = []): array
{
    return array_replace([
        'price_id' => $priceId,
        'provider' => 'stripe',
        'issued_at' => now()->timestamp,
        'nonce' => 'wordpress-test-nonce-1234567890',
        'key_id' => 'default',
        'source' => 'wordpress',
        'campaign' => 'pricing-page',
        'return_url' => 'https://www.oneploy.test/pricing',
    ], array_diff_key($overrides, ['tamper_price_after_signing' => true]));
}

function wordpressHandoffUrl(int $priceId, array $overrides = []): string
{
    $payload = wordpressHandoffPayload($priceId, $overrides);
    ksort($payload);
    $signature = hash_hmac('sha256', http_build_query($payload, '', '&', PHP_QUERY_RFC3986), 'wordpress-bridge-test-secret');
    if ($overrides['tamper_price_after_signing'] ?? false) {
        $payload['price_id'] = $priceId + 1;
    }

    return '/marketing/checkout?'.http_build_query([...$payload, 'signature' => $signature], '', '&', PHP_QUERY_RFC3986);
}
