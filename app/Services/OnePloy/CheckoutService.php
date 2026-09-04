<?php

namespace App\Services\OnePloy;

use App\Exceptions\OnePloy\PaymentInitiationException;
use App\Jobs\OnePloy\ProvisionDomainJob;
use App\Models\OneployCheckoutSession;
use App\Models\OneployCommerceSubscription;
use App\Models\OneployDomain;
use App\Models\OneployInvoice;
use App\Models\OneployOrder;
use App\Models\OneployPayment;
use App\Models\OneployPaymentWebhookEvent;
use App\Models\OneployPrice;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CheckoutService
{
    public function __construct(
        private readonly PayPalClient $payPal,
        private readonly PaymentProviderManager $paymentProviders,
    ) {}

    public function create(array $payload, Team $team, ?int $userId = null): OneployCheckoutSession
    {
        $effectiveAt = now();
        $price = OneployPrice::query()
            ->with('planVersion.plan.product')
            ->whereKey($payload['price_id'])
            ->where('status', 'active')
            ->effectiveAt($effectiveAt)
            ->whereHas('planVersion', fn ($query) => $query
                ->where('status', 'published')
                ->effectiveAt($effectiveAt))
            ->whereHas('planVersion.plan', fn ($query) => $query->where('is_active', true))
            ->whereHas('planVersion.plan.product', fn ($query) => $query->where('is_active', true))
            ->firstOrFail();
        $planVersion = $price->planVersion;
        $plan = $planVersion?->plan;
        $product = $plan?->product;
        $idempotency = $payload['idempotency_key'] ?? null;

        if ($idempotency) {
            $existing = OneployCheckoutSession::query()
                ->where('team_id', $team->id)
                ->where('idempotency_key', $idempotency)
                ->first();
            if ($existing) {
                if ((int) data_get($existing->items, '0.price_id') !== $price->id) {
                    throw new RuntimeException('This idempotency key belongs to a different checkout.');
                }

                return $existing;
            }
        }

        return OneployCheckoutSession::create([
            'team_id' => $team->id,
            'user_id' => $userId,
            'status' => 'open',
            'currency' => $price->currency,
            'locale' => $payload['locale'] ?? null,
            'idempotency_key' => $idempotency,
            'items' => [[
                'type' => 'plan',
                'price_id' => $price->id,
                'plan_version_id' => $price->plan_version_id,
                'product_id' => $product?->id,
                'product' => $product?->slug,
                'product_name' => $product?->name,
                'plan_id' => $plan?->id,
                'plan' => $plan?->slug,
                'plan_name' => $plan?->name,
                'plan_version' => $planVersion?->version,
                'currency' => $price->currency,
                'interval' => $price->interval,
                'quantity' => 1,
                'unit_amount_minor' => $price->amount_minor,
                'subtotal_minor' => $price->amount_minor,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'amount_minor' => $price->amount_minor,
            ]],
            'attribution' => $payload['attribution'] ?? null,
            'amount_minor' => $price->amount_minor,
            'coupon_code' => $payload['coupon'] ?? null,
            'expires_at' => now()->addHours(24),
        ]);
    }

    public function startPayment(
        OneployCheckoutSession $session,
        string $provider,
        string $returnUrl,
        string $cancelUrl,
    ): OneployCheckoutSession {
        $provider = strtolower($provider);
        $client = $this->paymentProviders->provider($provider);

        $state = DB::transaction(function () use ($session, $provider): string {
            $locked = OneployCheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'paid') {
                return 'paid';
            }
            if ($locked->expires_at?->isPast()) {
                $locked->update(['status' => 'expired']);

                return 'expired';
            }
            if ($locked->status === 'cancelled') {
                return 'cancelled';
            }
            if (filled($locked->provider) && ! hash_equals((string) $locked->provider, $provider)) {
                throw new RuntimeException("This checkout is already assigned to {$locked->provider}.");
            }
            if (
                $locked->provider === $provider
                && $locked->status === 'pending_provider'
                && filled($locked->provider_reference)
            ) {
                return 'ready';
            }
            if (
                $locked->status === 'initiating_provider'
                && $locked->updated_at?->isAfter(now()->subMinutes(2))
            ) {
                return 'initiating';
            }

            $locked->update([
                'status' => 'initiating_provider',
                'provider' => $provider,
                'provider_reference' => null,
                'approval_url' => null,
                'failure_reason' => null,
                'provider_payload' => null,
            ]);

            return 'start';
        });

        if ($state === 'paid') {
            throw new RuntimeException('This checkout has already been paid.');
        }
        if ($state === 'expired') {
            throw new RuntimeException('This checkout has expired.');
        }
        if ($state === 'cancelled') {
            throw new RuntimeException('This checkout was cancelled.');
        }
        if ($state === 'initiating') {
            throw new RuntimeException('This checkout is already being initiated.');
        }
        if ($state === 'ready') {
            return $session->fresh();
        }

        try {
            $result = $client->initiate($session->fresh(), $returnUrl, $cancelUrl);
        } catch (Throwable $exception) {
            OneployCheckoutSession::query()
                ->whereKey($session->id)
                ->where('provider', $provider)
                ->where('status', 'initiating_provider')
                ->update([
                    'status' => 'open',
                    'failure_reason' => 'The payment provider could not start checkout.',
                ]);
            Log::warning('oneploy.payment.initiation_failed', [
                'checkout_id' => $session->uuid,
                'team_id' => $session->team_id,
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            throw new PaymentInitiationException;
        }

        return DB::transaction(function () use ($session, $provider, $result): OneployCheckoutSession {
            $locked = OneployCheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') {
                return $locked;
            }
            if ($locked->provider !== $provider || $locked->status !== 'initiating_provider') {
                throw new PaymentInitiationException;
            }

            $locked->update([
                'status' => 'pending_provider',
                'provider_reference' => $result['provider_reference'],
                'approval_url' => $result['approval_url'],
                'failure_reason' => null,
                'provider_payload' => $result['public_payload'],
            ]);

            return $locked->fresh();
        });
    }

    public function startPayPal(OneployCheckoutSession $session, string $returnUrl, string $cancelUrl): string
    {
        $session = $this->startPayment($session, 'paypal', $returnUrl, $cancelUrl);
        if (blank($session->approval_url)) {
            throw new PaymentInitiationException;
        }

        return $session->approval_url;
    }

    public function completePayPal(OneployCheckoutSession $session, string $orderId): OneployOrder
    {
        if ($session->provider !== 'paypal' || ! hash_equals((string) $session->provider_reference, $orderId)) {
            throw new RuntimeException('PayPal order does not belong to this checkout.');
        }

        if ($session->status === 'paid') {
            return OneployOrder::query()->where('checkout_session_id', $session->id)->firstOrFail();
        }

        $capture = $this->payPal->captureOrder($orderId);
        $payment = $this->payPal->paymentData($capture);
        $this->assertPaymentMatches($session, $payment);

        return $this->markPaid(
            $session,
            'paypal',
            (string) $payment['provider_reference'],
            [
                'event_id' => $orderId,
                'event_type' => 'PAYPAL.ORDER.CAPTURE',
                'order_status' => $payment['status'],
            ],
        );
    }

    public function markPaid(OneployCheckoutSession $session, string $provider, string $providerReference, array $raw = []): OneployOrder
    {
        $order = DB::transaction(function () use ($session, $provider, $providerReference, $raw) {
            $session = OneployCheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status === 'paid') {
                return OneployOrder::query()->where('checkout_session_id', $session->id)->firstOrFail();
            }

            $existingPayment = OneployPayment::query()
                ->with('invoice.order')
                ->where('provider', $provider)
                ->where('provider_reference', $providerReference)
                ->first();
            if ($existingPayment) {
                if ($existingPayment->invoice?->order?->checkout_session_id === $session->id) {
                    return $existingPayment->invoice->order;
                }

                throw new RuntimeException('This provider payment was already applied to another checkout.');
            }

            $session->update([
                'status' => 'paid',
                'completed_at' => now(),
            ]);

            $order = OneployOrder::create([
                'team_id' => $session->team_id,
                'checkout_session_id' => $session->id,
                'status' => 'paid',
                'currency' => $session->currency,
                'amount_minor' => $session->amount_minor,
                'lines' => $session->items,
                'metadata' => ['provider' => $provider, 'provider_reference' => $providerReference, 'raw_keys' => array_keys($raw)],
            ]);

            $invoice = OneployInvoice::create([
                'team_id' => $session->team_id,
                'order_id' => $order->id,
                'status' => 'paid',
                'currency' => $session->currency,
                'amount_minor' => $session->amount_minor,
                'lines' => $session->items,
                'paid_at' => now(),
            ]);

            OneployPayment::create([
                'team_id' => $session->team_id,
                'invoice_id' => $invoice->id,
                'provider' => $provider,
                'status' => 'succeeded',
                'currency' => $session->currency,
                'amount_minor' => $session->amount_minor,
                'provider_reference' => $providerReference,
                'raw' => [
                    'event_id' => data_get($raw, 'event_id'),
                    'event_type' => data_get($raw, 'event_type'),
                ],
            ]);

            $priceId = data_get($session->items, '0.price_id');
            $price = OneployPrice::query()->with('planVersion.plan.product')->find($priceId);
            if ($price && $session->team_id) {
                OneployCommerceSubscription::updateOrCreate(
                    [
                        'team_id' => $session->team_id,
                        'product_id' => $price->planVersion?->plan?->product_id,
                    ],
                    [
                        'plan_version_id' => $price->plan_version_id,
                        'price_id' => $price->id,
                        'status' => 'active',
                        'current_period_ends_at' => $price->interval === 'yearly' ? now()->addYear() : now()->addMonth(),
                        'entitlement_snapshot' => $price->planVersion?->entitlements ?? [],
                    ]
                );
                $team = Team::find($session->team_id);
                $planSlug = $price->planVersion?->plan?->slug;
                if (
                    $team
                    && $price->planVersion?->plan?->product?->family === 'app_hosting'
                    && $planSlug
                    && in_array($planSlug, ['starter', 'pro', 'free', 'unlimited'], true)
                ) {
                    $team->assignPlan($planSlug);
                }
            }

            return $order;
        });

        $this->queuePaidDomainRegistrations($session->id);

        return $order;
    }

    public function recordWebhook(string $provider, string $eventId, array $payload): OneployPaymentWebhookEvent
    {
        return OneployPaymentWebhookEvent::firstOrCreate(
            ['provider' => $provider, 'provider_event_id' => $eventId],
            ['status' => 'received', 'payload' => $payload]
        );
    }

    /**
     * @param  array{checkout_uuid: ?string, provider_reference: ?string, amount_minor: ?int, currency: ?string, status: string}  $payment
     */
    public function assertPaymentMatches(OneployCheckoutSession $session, array $payment): void
    {
        if (
            $payment['checkout_uuid'] !== $session->uuid
            || $payment['provider_reference'] === null
            || $payment['amount_minor'] !== $session->amount_minor
            || $payment['currency'] !== strtoupper($session->currency)
            || $payment['status'] !== 'COMPLETED'
        ) {
            throw new RuntimeException('The authoritative payment does not match this checkout.');
        }
    }

    private function queuePaidDomainRegistrations(int $checkoutSessionId): void
    {
        $domainIds = DB::transaction(function () use ($checkoutSessionId): array {
            OneployDomain::query()
                ->where('checkout_session_id', $checkoutSessionId)
                ->where('status', 'pending_payment')
                ->update([
                    'status' => 'pending_registration',
                    'last_error' => null,
                ]);

            return OneployDomain::query()
                ->where('checkout_session_id', $checkoutSessionId)
                ->where('status', 'pending_registration')
                ->pluck('id')
                ->all();
        });

        foreach ($domainIds as $domainId) {
            ProvisionDomainJob::dispatch($domainId)->afterCommit();
        }
    }
}
