<?php

namespace App\Services\OnePloy;

use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class PaymentWebhookVerifier
{
    public function __construct(private readonly PayPalClient $payPal) {}

    /**
     * @return array{event_id: string, provider_reference: string, checkout_uuid: ?string, amount_minor: ?int, currency: ?string, is_payment_success: bool, audit_payload: array<string, mixed>}|null
     */
    public function verify(Request $request, string $provider): ?array
    {
        return match ($provider) {
            'stripe' => $this->verifyStripe($request),
            'razorpay' => $this->verifyRazorpay($request),
            'paypal' => $this->verifyPayPal($request),
            default => null,
        };
    }

    /**
     * @return array{event_id: string, provider_reference: string, checkout_uuid: ?string, amount_minor: ?int, currency: ?string, is_payment_success: bool, audit_payload: array<string, mixed>}|null
     */
    private function verifyPayPal(Request $request): ?array
    {
        if (! $this->payPal->verifyWebhook($request)) {
            return null;
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return null;
        }

        $eventId = data_get($payload, 'id');
        $eventType = (string) data_get($payload, 'event_type', '');
        $resource = (array) data_get($payload, 'resource', []);
        if (! is_string($eventId) || $eventId === '') {
            return null;
        }

        $checkoutUuid = data_get($resource, 'custom_id') ?? data_get($resource, 'invoice_id');
        $orderId = data_get($resource, 'supplementary_data.related_ids.order_id');
        if (! is_string($checkoutUuid) && is_string($orderId) && $orderId !== '') {
            $payment = $this->payPal->paymentData($this->payPal->order($orderId));
            $checkoutUuid = $payment['checkout_uuid'];
        }

        return $this->normalized(
            provider: 'paypal',
            eventId: $eventId,
            eventType: $eventType,
            providerReference: (string) (data_get($resource, 'id') ?: $orderId ?: $eventId),
            checkoutUuid: is_string($checkoutUuid) ? $checkoutUuid : null,
            amountMinor: $this->paypalMinorAmount(data_get($resource, 'amount.value')),
            currency: $this->currencyOrNull(data_get($resource, 'amount.currency_code')),
            isPaymentSuccess: $eventType === 'PAYMENT.CAPTURE.COMPLETED'
                && strtoupper((string) data_get($resource, 'status', '')) === 'COMPLETED',
            paymentStatus: (string) data_get($resource, 'status', ''),
        );
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

    private function paypalMinorAmount(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value * 100) : null;
    }
}
