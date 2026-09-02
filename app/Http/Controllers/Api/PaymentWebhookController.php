<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OneployCheckoutSession;
use App\Services\OnePloy\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, string $provider, CheckoutService $checkout): JsonResponse
    {
        $payload = $request->all();
        $eventId = (string) ($request->header('X-OnePloy-Idempotency') ?: data_get($payload, 'id') ?: data_get($payload, 'event_id') ?: sha1($request->getContent()));

        if (! $this->verify($request, $provider)) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        $event = $checkout->recordWebhook($provider, $eventId, $payload);
        if ($event->status === 'processed') {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $sessionUuid = data_get($payload, 'checkout_id') ?: data_get($payload, 'data.object.client_reference_id');
        if ($sessionUuid) {
            $session = OneployCheckoutSession::query()->where('uuid', $sessionUuid)->first();
            if ($session) {
                $checkout->markPaid($session, $provider, $eventId, $payload);
            }
        }

        $event->update(['status' => 'processed', 'processed_at' => now()]);
        Log::info('oneploy.payment.webhook', ['provider' => $provider, 'event' => $eventId]);

        return response()->json(['ok' => true]);
    }

    private function verify(Request $request, string $provider): bool
    {
        return match ($provider) {
            'stripe' => $this->verifyStripe($request),
            'razorpay' => hash_equals((string) config('oneploy.payments.razorpay_webhook_secret'), (string) $request->header('X-Razorpay-Signature', '')),
            'paypal' => filled(config('oneploy.payments.paypal_webhook_id')),
            'manual' => true,
            default => false,
        };
    }

    private function verifyStripe(Request $request): bool
    {
        $secret = (string) config('oneploy.payments.stripe_webhook_secret');
        if ($secret === '') {
            return false;
        }
        $signature = (string) $request->header('Stripe-Signature', '');

        return filled($signature);
    }
}
