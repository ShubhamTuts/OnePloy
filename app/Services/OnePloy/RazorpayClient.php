<?php

namespace App\Services\OnePloy;

use App\Contracts\OnePloy\PaymentProviderClient;
use App\Models\OneployCheckoutSession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class RazorpayClient implements PaymentProviderClient
{
    public function provider(): string
    {
        return 'razorpay';
    }

    public function isConfigured(): bool
    {
        $baseUrl = (string) config('oneploy.payments.razorpay_base_url');

        return parse_url($baseUrl, PHP_URL_SCHEME) === 'https'
            && filled(parse_url($baseUrl, PHP_URL_HOST))
            && filled(config('oneploy.payments.razorpay_key'))
            && filled(config('oneploy.payments.razorpay_secret'))
            && filled(config('oneploy.payments.razorpay_webhook_secret'));
    }

    /**
     * @return array{provider_reference: string, approval_url: null, status: string, public_payload: array<string, mixed>}
     */
    public function initiate(OneployCheckoutSession $checkout, string $returnUrl, string $cancelUrl): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Razorpay is not configured for verified payments.');
        }

        $receipt = mb_substr('op_'.$checkout->uuid, 0, 40);
        $order = $this->findExistingOrder($checkout, $receipt);
        if ($order === null) {
            $order = $this->request()
                ->post('/v1/orders', [
                    'amount' => $checkout->amount_minor,
                    'currency' => strtoupper($checkout->currency),
                    'receipt' => $receipt,
                    'notes' => [
                        'oneploy_checkout_id' => $checkout->uuid,
                        'oneploy_team_id' => (string) $checkout->team_id,
                    ],
                ])
                ->throw()
                ->json();
        }

        $reference = data_get($order, 'id');
        $status = strtolower((string) data_get($order, 'status'));
        if (
            ! is_string($reference)
            || $reference === ''
            || data_get($order, 'entity') !== 'order'
            || data_get($order, 'amount') !== $checkout->amount_minor
            || strtoupper((string) data_get($order, 'currency')) !== strtoupper($checkout->currency)
            || data_get($order, 'receipt') !== $receipt
            || ! in_array($status, ['created', 'attempted', 'paid'], true)
        ) {
            throw new RuntimeException('Razorpay returned an order that does not match the OnePloy order.');
        }

        return [
            'provider_reference' => $reference,
            'approval_url' => null,
            'status' => $status,
            'public_payload' => [
                'key_id' => (string) config('oneploy.payments.razorpay_key'),
                'order_id' => $reference,
                'status' => $status,
                'amount_minor' => $checkout->amount_minor,
                'currency' => strtoupper($checkout->currency),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function findExistingOrder(OneployCheckoutSession $checkout, string $receipt): ?array
    {
        $response = $this->request()
            ->retry([200, 500, 1000], function (Throwable $exception): bool {
                return ! ($exception instanceof RequestException) || $exception->response->serverError();
            }, throw: false)
            ->get('/v1/orders', ['receipt' => $receipt, 'count' => 1])
            ->throw();

        $order = collect($response->json('items', []))->first(function (mixed $order) use ($checkout, $receipt): bool {
            return is_array($order)
                && data_get($order, 'receipt') === $receipt
                && data_get($order, 'amount') === $checkout->amount_minor
                && strtoupper((string) data_get($order, 'currency')) === strtoupper($checkout->currency);
        });

        return is_array($order) ? $order : null;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('oneploy.payments.razorpay_base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withBasicAuth(
                (string) config('oneploy.payments.razorpay_key'),
                (string) config('oneploy.payments.razorpay_secret'),
            )
            ->timeout(20)
            ->connectTimeout(5);
    }
}
