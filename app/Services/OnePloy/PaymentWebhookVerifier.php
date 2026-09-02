<?php

namespace App\Services\OnePloy;

use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class PaymentWebhookVerifier
{
    /**
     * @return array{event_id: string, provider_reference: string, checkout_uuid: ?string, amount_minor: ?int, currency: ?string, is_payment_success: bool, audit_payload: array<string, mixed>}|null
     */
    public function verify(Request $request, string $provider): ?array
    {
        return match ($provider) {
            'stripe' => $this->verifyStripe($request),
            'razorpay' => $this->verifyRazorpay($request),
            default => null,
        };
    }

    /**
     * @return array{event_id: string, provider_reference: string, checkout_uuid: ?string, amount_minor: ?int, currency: ?string, is_payment_success: bool, audit_payload: array<string, mixed>}|null
     */
    private function verifyStripe(Request $request): ?array
    {
        $secret = (string) config('oneploy.payments.stripe_webhook_secret');
        $signature = (string) $request->header('Stripe-Signature', '');
        if ($secret === '' || $signature === '') {
            return null;
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), $signature, $secret);
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return null;
        }

        $payload = $event->toArray();
        $object = (array) data_get($payload, 'data.object', []);
        $eventType = (string) $event->type;
        $paymentStatus = (string) data_get($object, 'payment_status', '');
        $isSuccessfulType = in_array($eventType, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'payment_intent.succeeded',
        ], true);
        $isPaidCheckout = ! str_starts_with($eventType, 'checkout.session.')
            || in_array($paymentStatus, ['paid', 'no_payment_required'], true);
        $checkoutUuid = data_get($object, 'client_reference_id')
            ?: data_get($object, 'metadata.oneploy_checkout_id')
            ?: data_get($object, 'metadata.checkout_id');

        return $this->normalized(
            provider: 'stripe',
            eventId: (string) $event->id,
            eventType: $eventType,
            providerReference: (string) (data_get($object, 'payment_intent') ?: data_get($object, 'id') ?: $event->id),
            checkoutUuid: is_string($checkoutUuid) ? $checkoutUuid : null,
            amountMinor: $this->integerOrNull(data_get($object, 'amount_total') ?? data_get($object, 'amount_received')),
            currency: $this->currencyOrNull(data_get($object, 'currency')),
            isPaymentSuccess: $isSuccessfulType && $isPaidCheckout,
            paymentStatus: $paymentStatus,
        );
    }

    /**
     * @return array{event_id: string, provider_reference: string, checkout_uuid: ?string, amount_minor: ?int, currency: ?string, is_payment_success: bool, audit_payload: array<string, mixed>}|null
     */
    private function verifyRazorpay(Request $request): ?array
    {
        $secret = (string) config('oneploy.payments.razorpay_webhook_secret');
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        if ($secret === '' || $signature === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return null;
        }

        $eventType = (string) data_get($payload, 'event', '');
        $entity = (array) ($eventType === 'order.paid'
            ? data_get($payload, 'payload.order.entity', [])
            : data_get($payload, 'payload.payment.entity', []));
        $providerReference = (string) (data_get($entity, 'id') ?: sha1($request->getContent()));
        $eventId = (string) ($request->header('X-Razorpay-Event-Id') ?: $eventType.':'.$providerReference);
        $checkoutUuid = data_get($entity, 'notes.oneploy_checkout_id') ?: data_get($entity, 'notes.checkout_id');

        return $this->normalized(
            provider: 'razorpay',
            eventId: $eventId,
            eventType: $eventType,
            providerReference: $providerReference,
            checkoutUuid: is_string($checkoutUuid) ? $checkoutUuid : null,
            amountMinor: $this->integerOrNull(data_get($entity, 'amount')),
            currency: $this->currencyOrNull(data_get($entity, 'currency')),
            isPaymentSuccess: in_array($eventType, ['payment.captured', 'order.paid'], true),
            paymentStatus: (string) data_get($entity, 'status', ''),
        );
    }

    /**
     * @return array{event_id: string, provider_reference: string, checkout_uuid: ?string, amount_minor: ?int, currency: ?string, is_payment_success: bool, audit_payload: array<string, mixed>}
     */
    private function normalized(
        string $provider,
        string $eventId,
        string $eventType,
        string $providerReference,
        ?string $checkoutUuid,
        ?int $amountMinor,
        ?string $currency,
        bool $isPaymentSuccess,
        string $paymentStatus,
    ): array {
        return [
            'event_id' => $eventId,
            'provider_reference' => $providerReference,
            'checkout_uuid' => $checkoutUuid,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'is_payment_success' => $isPaymentSuccess,
            'audit_payload' => [
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'object_id' => $providerReference,
                'checkout_uuid' => $checkoutUuid,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_status' => $paymentStatus,
            ],
        ];
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function currencyOrNull(mixed $value): ?string
    {
        return is_string($value) && strlen($value) === 3 ? strtoupper($value) : null;
    }
}
