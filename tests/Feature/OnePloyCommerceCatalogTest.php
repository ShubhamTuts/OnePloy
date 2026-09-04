<?php

use App\Models\OneployInvoice;
use App\Models\OneployPlan;
use App\Models\OneployPlanVersion;
use App\Models\OneployPrice;
use App\Models\OneployProduct;
use App\Models\Team;
use App\Services\OnePloy\CatalogService;
use App\Services\OnePloy\CheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->product = OneployProduct::query()->create([
        'slug' => 'test-hosting',
        'name' => 'Test Hosting',
        'family' => 'app_hosting',
        'is_active' => true,
    ]);
    $this->plan = OneployPlan::query()->create([
        'product_id' => $this->product->id,
        'slug' => 'test-plan',
        'name' => 'Test Plan',
        'is_active' => true,
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('plan version and price effective-at scopes use inclusive starts and exclusive ends', function () {
    $at = CarbonImmutable::parse('2026-09-04 12:00:00 UTC');
    $effectiveVersion = createCommerceVersion($this->plan, 1, [
        'effective_from' => $at,
        'effective_until' => $at->addDay(),
    ]);
    $unboundedVersion = createCommerceVersion($this->plan, 2);
    createCommerceVersion($this->plan, 3, ['effective_from' => $at->addSecond()]);
    createCommerceVersion($this->plan, 4, ['effective_until' => $at]);

    expect(OneployPlanVersion::query()->effectiveAt($at)->pluck('id')->all())
        ->toEqualCanonicalizing([$effectiveVersion->id, $unboundedVersion->id]);

    $effectivePrice = createCommercePrice($effectiveVersion, 1000, [
        'effective_from' => $at,
        'effective_until' => $at->addDay(),
    ]);
    $unboundedPrice = createCommercePrice($effectiveVersion, 1100, ['interval' => 'yearly']);
    createCommercePrice($effectiveVersion, 1200, [
        'currency' => 'INR',
        'effective_from' => $at->addSecond(),
    ]);
    createCommercePrice($effectiveVersion, 1300, [
        'currency' => 'EUR',
        'effective_until' => $at,
    ]);

    expect(OneployPrice::query()->effectiveAt($at)->pluck('id')->all())
        ->toEqualCanonicalizing([$effectivePrice->id, $unboundedPrice->id]);
});

test('publishedVersion returns the highest published version effective at the requested time', function () {
    $at = CarbonImmutable::parse('2026-09-04 12:00:00 UTC');
    $current = createCommerceVersion($this->plan, 1, [
        'effective_from' => $at->subDay(),
        'effective_until' => $at->addDay(),
    ]);
    createCommerceVersion($this->plan, 2, ['effective_from' => $at->addDay()]);
    createCommerceVersion($this->plan, 3, ['status' => 'draft']);

    expect($this->plan->publishedVersion($at)?->is($current))->toBeTrue();
});

test('catalogue deterministically selects the latest effective price from the effective published version', function () {
    $at = CarbonImmutable::parse('2026-09-04 12:00:00 UTC');
    CarbonImmutable::setTestNow($at);

    $currentVersion = createCommerceVersion($this->plan, 1, [
        'features' => ['current'],
        'effective_from' => $at->subDay(),
    ]);
    createCommerceVersion($this->plan, 2, [
        'features' => ['future'],
        'effective_from' => $at->addDay(),
    ]);
    createCommercePrice($currentVersion, 1000, ['effective_from' => $at->subDays(2)]);
    $selected = createCommercePrice($currentVersion, 1200, ['effective_from' => $at->subHour()]);
    createCommercePrice($currentVersion, 1300, ['effective_from' => $at->addHour()]);

    $catalogue = app(CatalogService::class)->catalogue('USD', 'monthly');
    $product = collect($catalogue)->firstWhere('slug', $this->product->slug);

    expect(data_get($product, 'plans.0.features'))->toBe(['current'])
        ->and(data_get($product, 'plans.0.price.id'))->toBe($selected->id)
        ->and(data_get($product, 'plans.0.price.amount_minor'))->toBe(1200);
});

test('persisted price commercial identity and value cannot be changed', function (string $attribute, mixed $replacement) {
    $version = createCommerceVersion($this->plan, 1);
    $price = createCommercePrice($version, 1900);

    expect(fn () => $price->update([$attribute => $replacement]))
        ->toThrow(RuntimeException::class, 'Persisted prices are immutable');
})->with([
    'plan version' => ['plan_version_id', 999],
    'currency' => ['currency', 'INR'],
    'billing interval' => ['interval', 'yearly'],
    'amount' => ['amount_minor', 2000],
]);

test('catalog seed fails closed instead of changing an existing price amount', function () {
    app(CatalogService::class)->seed();
    $price = OneployPrice::query()
        ->where('currency', 'USD')
        ->where('interval', 'monthly')
        ->whereHas('planVersion.plan.product', fn ($query) => $query->where('family', 'app_hosting'))
        ->firstOrFail();
    $unexpectedAmount = $price->amount_minor + 1;
    OneployPrice::query()->whereKey($price)->update(['amount_minor' => $unexpectedAmount]);

    expect(fn () => app(CatalogService::class)->seed())
        ->toThrow(RuntimeException::class, 'Seeded price amount conflicts with the persisted price');

    expect($price->fresh()->amount_minor)->toBe($unexpectedAmount);
});

test('checkout stores an authoritative line snapshot and copies it exactly to the order and invoice', function () {
    $version = createCommerceVersion($this->plan, 1);
    $price = createCommercePrice($version, 1900);
    $team = Team::factory()->create();

    $checkout = app(CheckoutService::class)->create(['price_id' => $price->id], $team);
    $expectedLine = [
        'type' => 'plan',
        'price_id' => $price->id,
        'plan_version_id' => $version->id,
        'product_id' => $this->product->id,
        'product' => 'test-hosting',
        'product_name' => 'Test Hosting',
        'plan_id' => $this->plan->id,
        'plan' => 'test-plan',
        'plan_name' => 'Test Plan',
        'plan_version' => 1,
        'currency' => 'USD',
        'interval' => 'monthly',
        'quantity' => 1,
        'unit_amount_minor' => 1900,
        'subtotal_minor' => 1900,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'amount_minor' => 1900,
    ];

    expect($checkout->items)->toBe([$expectedLine])
        ->and($checkout->amount_minor)->toBe(1900);

    OneployPrice::query()->whereKey($price)->update(['amount_minor' => 9999]);
    $order = app(CheckoutService::class)->markPaid($checkout, 'paypal', 'CAPTURE-SNAPSHOT');
    $invoice = OneployInvoice::query()->where('order_id', $order->id)->firstOrFail();

    expect($order->lines)->toBe($checkout->items)
        ->and($invoice->lines)->toBe($checkout->items)
        ->and($order->amount_minor)->toBe(1900)
        ->and($invoice->amount_minor)->toBe(1900);
});

/** @param array<string, mixed> $overrides */
function createCommerceVersion(OneployPlan $plan, int $version, array $overrides = []): OneployPlanVersion
{
    return OneployPlanVersion::query()->create(array_replace([
        'plan_id' => $plan->id,
        'version' => $version,
        'status' => 'published',
        'published_at' => now(),
        'effective_from' => null,
        'effective_until' => null,
        'features' => [],
        'entitlements' => [],
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function createCommercePrice(OneployPlanVersion $version, int $amountMinor, array $overrides = []): OneployPrice
{
    return OneployPrice::query()->create(array_replace([
        'plan_version_id' => $version->id,
        'currency' => 'USD',
        'amount_minor' => $amountMinor,
        'interval' => 'monthly',
        'status' => 'active',
        'effective_from' => null,
        'effective_until' => null,
    ], $overrides));
}
