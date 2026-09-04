<?php

namespace App\Services\OnePloy;

use App\Contracts\OnePloy\PaymentProviderClient;
use App\Models\OneployCheckoutSession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PayPalClient implements PaymentProviderClient
{
    public function provider(): string
    {
        return 'paypal';
    }

    public function isConfigured(): bool
    {
        $baseUrl = (string) config('oneploy.payments.paypal_base_url');

        return parse_url($baseUrl, PHP_URL_SCHEME) === 'https'
            && filled(parse_url($baseUrl, PHP_URL_HOST))
            && filled(config('oneploy.payments.paypal_client_id'))
            && filled(config('oneploy.payments.paypal_secret'))
            && filled(config('oneploy.payments.paypal_webhook_id'));
    }

    /**
     * @return array{id: string, approval_url: string, status: string}
     */
    public function createOrder(OneployCheckoutSession $checkout, string $returnUrl, string $cancelUrl): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('PayPal client ID, secret, and webhook ID must be configured before checkout is enabled.');
        }

        $response = $this->request()
            ->withHeaders([
                'PayPal-Request-Id' => substr(hash('sha256', 'checkout:'.$checkout->uuid), 0, 25),
                'Prefer' => 'return=representation',
            ])
            ->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $checkout->uuid,
                    'custom_id' => $checkout->uuid,
                    'invoice_id' => $checkout->uuid,
                    'description' => 'OnePloy checkout '.$checkout->uuid,
                    'amount' => [
                        'currency_code' => strtoupper($checkout->currency),
                        'value' => $this->majorAmount($checkout->amount_minor),
                    ],
                ]],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'brand_name' => 'OnePloy',
                            'shipping_preference' => 'NO_SHIPPING',
                            'user_action' => 'PAY_NOW',
                            'return_url' => $returnUrl,
                            'cancel_url' => $cancelUrl,
                        ],
                    ],
                ],
            ])
            ->throw();

        $links = collect($response->json('links', []));
        $approvalUrl = data_get($links->firstWhere('rel', 'payer-action'), 'href')
            ?? data_get($links->firstWhere('rel', 'approve'), 'href');
        $id = $response->json('id');

        if (! is_string($id) || ! is_string($approvalUrl)) {
            throw new RuntimeException('PayPal did not return an order and approval URL.');
        }

        return [
            'id' => $id,
            'approval_url' => $approvalUrl,
            'status' => (string) $response->json('status', 'CREATED'),
        ];
    }

    /**
     * @return array{provider_reference: string, approval_url: string, status: string, public_payload: array<string, mixed>}
     */
    public function initiate(OneployCheckoutSession $checkout, string $returnUrl, string $cancelUrl): array
    {
        $order = $this->createOrder($checkout, $returnUrl, $cancelUrl);

        return [
            'provider_reference' => $order['id'],
            'approval_url' => $order['approval_url'],
            'status' => $order['status'],
            'public_payload' => [
                'order_id' => $order['id'],
                'status' => $order['status'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function captureOrder(string $orderId): array
    {
        return $this->request()
            ->withHeaders([
                'PayPal-Request-Id' => substr(hash('sha256', 'capture:'.$orderId), 0, 25),
                'Prefer' => 'return=representation',
            ])
            ->withBody('{}', 'application/json')
            ->post('/v2/checkout/orders/'.rawurlencode($orderId).'/capture')
            ->throw()
            ->json();
    }

    /** @return array<string, mixed> */
    public function order(string $orderId): array
    {
        return $this->request()
            ->get('/v2/checkout/orders/'.rawurlencode($orderId))
            ->throw()
            ->json();
    }

    public function verifyWebhook(Request $request): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            return false;
        }

        $response = $this->request()->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => (string) $request->header('PayPal-Auth-Algo'),
            'cert_url' => (string) $request->header('PayPal-Cert-Url'),
            'transmission_id' => (string) $request->header('PayPal-Transmission-Id'),
            'transmission_sig' => (string) $request->header('PayPal-Transmission-Sig'),
            'transmission_time' => (string) $request->header('PayPal-Transmission-Time'),
            'webhook_id' => (string) config('oneploy.payments.paypal_webhook_id'),
            'webhook_event' => $event,
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{checkout_uuid: ?string, provider_reference: ?string, amount_minor: ?int, currency: ?string, status: string}
     */
    public function paymentData(array $order): array
    {
        $purchaseUnit = (array) data_get($order, 'purchase_units.0', []);
        $capture = (array) data_get($purchaseUnit, 'payments.captures.0', []);
        $amount = data_get($capture, 'amount.value') ?? data_get($purchaseUnit, 'amount.value');

        return [
            'checkout_uuid' => $this->stringOrNull(data_get($purchaseUnit, 'custom_id') ?? data_get($purchaseUnit, 'reference_id')),
            'provider_reference' => $this->stringOrNull(data_get($capture, 'id') ?? data_get($order, 'id')),
            'amount_minor' => $this->minorAmount($amount),
            'currency' => $this->currencyOrNull(data_get($capture, 'amount.currency_code') ?? data_get($purchaseUnit, 'amount.currency_code')),
            'status' => strtoupper((string) (data_get($capture, 'status') ?? data_get($order, 'status', ''))),
        ];
    }

    private function request(): PendingRequest
    {
        if (blank(config('oneploy.payments.paypal_client_id')) || blank(config('oneploy.payments.paypal_secret'))) {
            throw new RuntimeException('PayPal API credentials are not configured.');
        }

        return Http::baseUrl(rtrim((string) config('oneploy.payments.paypal_base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([200, 500, 1000], function (\Throwable $exception): bool {
                return ! ($exception instanceof RequestException) || $exception->response->serverError();
            }, throw: false)
            ->withToken($this->accessToken());
    }

    private function accessToken(): string
    {
        $clientId = (string) config('oneploy.payments.paypal_client_id');
        $cacheKey = 'oneploy:paypal:token:'.hash('sha256', (string) config('oneploy.payments.paypal_base_url').$clientId);

        return Cache::remember($cacheKey, now()->addMinutes(45), function () use ($clientId): string {
            $response = Http::baseUrl(rtrim((string) config('oneploy.payments.paypal_base_url'), '/'))
                ->asForm()
                ->acceptJson()
                ->withBasicAuth($clientId, (string) config('oneploy.payments.paypal_secret'))
                ->timeout(15)
                ->connectTimeout(5)
                ->retry([200, 500, 1000], throw: false)
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials'])
                ->throw();

            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('PayPal did not return an access token.');
            }

            return $token;
        });
    }

    private function majorAmount(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, '.', '');
    }

    private function minorAmount(mixed $amount): ?int
    {
        if (! is_numeric($amount)) {
            return null;
        }

        return (int) round((float) $amount * 100);
    }

    private function currencyOrNull(mixed $currency): ?string
    {
        return is_string($currency) && Str::length($currency) === 3 ? Str::upper($currency) : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
