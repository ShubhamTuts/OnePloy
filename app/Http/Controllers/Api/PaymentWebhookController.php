<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OneployCheckoutSession;
use App\Services\OnePloy\CheckoutService;
use App\Services\OnePloy\PaymentWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(
        Request $request,
        string $provider,
        CheckoutService $checkout,
        PaymentWebhookVerifier $verifier,
    ): JsonResponse {
        $verified = $verifier->verify($request, $provider);
        if ($verified === null) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        $event = $checkout->recordWebhook($provider, $verified['event_id'], $verified['audit_payload']);
        if (in_array($event->status, ['processed', 'ignored', 'rejected'], true)) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        if (! $verified['is_payment_success'] || blank($verified['checkout_uuid'])) {
            $event->update(['status' => 'ignored', 'processed_at' => now()]);

            return response()->json(['ok' => true, 'ignored' => true], 202);
        }

        $session = OneployCheckoutSession::query()->where('uuid', $verified['checkout_uuid'])->first();
        if (! $session) {
            $event->update(['status' => 'ignored', 'processed_at' => now()]);

            return response()->json(['ok' => true, 'ignored' => true], 202);
        }

        if (blank($session->provider) || ! hash_equals((string) $session->provider, $provider)) {
            $event->update(['status' => 'rejected', 'processed_at' => now()]);

            return response()->json(['error' => 'payment provider does not match checkout'], 422);
        }

        if (
            $verified['amount_minor'] === null
            || $verified['amount_minor'] !== $session->amount_minor
            || $verified['currency'] === null
            || $verified['currency'] !== strtoupper($session->currency)
        ) {
            $event->update(['status' => 'rejected', 'processed_at' => now()]);

            return response()->json(['error' => 'payment does not match checkout'], 422);
        }

        $checkout->markPaid($session, $provider, $verified['provider_reference'], $verified['audit_payload']);
        $event->update(['status' => 'processed', 'processed_at' => now()]);
        Log::info('oneploy.payment.webhook', ['provider' => $provider, 'event' => $verified['event_id']]);

        return response()->json(['ok' => true]);
    }
}
