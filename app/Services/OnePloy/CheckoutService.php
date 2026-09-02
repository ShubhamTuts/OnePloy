<?php

namespace App\Services\OnePloy;

use App\Models\OneployCheckoutSession;
use App\Models\OneployCommerceSubscription;
use App\Models\OneployInvoice;
use App\Models\OneployOrder;
use App\Models\OneployPayment;
use App\Models\OneployPaymentWebhookEvent;
use App\Models\OneployPrice;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
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

    public function markPaid(OneployCheckoutSession $session, string $provider, string $providerReference, array $raw = []): OneployOrder
    {
        return DB::transaction(function () use ($session, $provider, $providerReference, $raw) {
            $session = OneployCheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status === 'paid') {
                return OneployOrder::query()->where('checkout_session_id', $session->id)->firstOrFail();
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
                    ['team_id' => $session->team_id],
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
    }

    public function recordWebhook(string $provider, string $eventId, array $payload): OneployPaymentWebhookEvent
    {
        return OneployPaymentWebhookEvent::firstOrCreate(
            ['provider' => $provider, 'provider_event_id' => $eventId],
            ['status' => 'received', 'payload' => $payload]
        );
    }
}
