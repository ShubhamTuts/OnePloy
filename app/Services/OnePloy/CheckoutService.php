<?php

namespace App\Services\OnePloy;

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
use RuntimeException;

class CheckoutService
{
    public function __construct(private readonly PayPalClient $payPal) {}

    public function create(array $payload, Team $team, ?int $userId = null): OneployCheckoutSession
    {
        $price = OneployPrice::query()
            ->with('planVersion.plan.product')
            ->whereKey($payload['price_id'])
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereNull('effective_from')
                ->orWhere('effective_from', '<=', now()))
            ->where(fn ($query) => $query
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', now()))
            ->whereHas('planVersion', fn ($query) => $query
                ->where('status', 'published')
                ->where(fn ($version) => $version
                    ->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now()))
                ->where(fn ($version) => $version
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now())))
            ->whereHas('planVersion.plan', fn ($query) => $query->where('is_active', true))
            ->whereHas('planVersion.plan.product', fn ($query) => $query->where('is_active', true))
            ->firstOrFail();
        $idempotency = $payload['idempotency_key'] ?? null;

        if ($idempotency) {
            $existing = OneployCheckoutSession::query()
                ->where('team_id', $team->id)
                ->where('idempotency_key', $idempotency)
                ->first();
            if ($existing) {
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
                'price_id' => $price->id,
                'product' => $price->planVersion?->plan?->product?->slug,
                'plan' => $price->planVersion?->plan?->slug,
            ]],
            'attribution' => $payload['attribution'] ?? null,
            'amount_minor' => $price->amount_minor,
            'coupon_code' => $payload['coupon'] ?? null,
            'expires_at' => now()->addHours(24),
        ]);
    }

    public function startPayPal(OneployCheckoutSession $session, string $returnUrl, string $cancelUrl): string
    {
        if ($session->status === 'paid') {
            throw new RuntimeException('This checkout has already been paid.');
        }
        if ($session->expires_at?->isPast()) {
            $session->update(['status' => 'expired']);

            throw new RuntimeException('This checkout has expired.');
        }

        if ($session->provider === 'paypal' && filled($session->approval_url) && filled($session->provider_reference)) {
            return $session->approval_url;
        }

        $order = $this->payPal->createOrder($session, $returnUrl, $cancelUrl);
        $session->update([
            'status' => 'pending_provider',
            'provider' => 'paypal',
            'provider_reference' => $order['id'],
            'approval_url' => $order['approval_url'],
            'failure_reason' => null,
            'provider_payload' => [
                'order_id' => $order['id'],
                'status' => $order['status'],
            ],
        ]);

        return $order['approval_url'];
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
