<?php

namespace App\Services\OnePloy;

use App\Contracts\OnePloy\PaymentProviderClient;
use App\Models\OneployCheckoutSession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class StripeCheckoutClient implements PaymentProviderClient
{
    public function provider(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        $baseUrl = (string) config('oneploy.payments.stripe_base_url');

        return parse_url($baseUrl, PHP_URL_SCHEME) === 'https'
            && filled(parse_url($baseUrl, PHP_URL_HOST))
            && filled(config('oneploy.payments.stripe_secret'))
            && filled(config('oneploy.payments.stripe_webhook_secret'));
    }

    /**
     * @return array{provider_reference: string, approval_url: string, status: string, public_payload: array<string, mixed>}
     */
    public function initiate(OneployCheckoutSession $checkout, string $returnUrl, string $cancelUrl): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Stripe is not configured for verified payments.');
        }

        $response = $this->request($checkout)
            ->post('/v1/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => $checkout->uuid,
                'success_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($checkout->currency),
                        'unit_amount' => $checkout->amount_minor,
                        'product_data' => [
                            'name' => (string) (data_get($checkout->items, '0.plan_name')
                                ?? data_get($checkout->items, '0.product_name')
                                ?? 'OnePloy order'),
                        ],
                    ],
                ]],
                'metadata' => [
                    'oneploy_checkout_id' => $checkout->uuid,
                    'oneploy_team_id' => (string) $checkout->team_id,
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'oneploy_checkout_id' => $checkout->uuid,
                        'oneploy_team_id' => (string) $checkout->team_id,
                    ],
                ],
            ])
            ->throw();

        $reference = $response->json('id');
        $approvalUrl = $response->json('url');
        $status = strtolower((string) $response->json('status'));
        $host = is_string($approvalUrl) ? strtolower((string) parse_url($approvalUrl, PHP_URL_HOST)) : '';

        if (
            ! is_string($reference)
            || $reference === ''
            || ! is_string($approvalUrl)
            || ! str_starts_with($approvalUrl, 'https://')
            || ($host !== 'checkout.stripe.com' && ! str_ends_with($host, '.stripe.com'))
            || $status !== 'open'
            || $response->json('amount_total') !== $checkout->amount_minor
            || strtolower((string) $response->json('currency')) !== strtolower($checkout->currency)
        ) {
            throw new RuntimeException('Stripe returned a checkout that does not match the OnePloy order.');
        }

        return [
            'provider_reference' => $reference,
            'approval_url' => $approvalUrl,
            'status' => $status,
            'public_payload' => [
                'checkout_session_id' => $reference,
                'status' => $status,
            ],
        ];
    }

    private function request(OneployCheckoutSession $checkout): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('oneploy.payments.stripe_base_url'), '/'))
            ->acceptJson()
            ->asForm()
            ->withToken((string) config('oneploy.payments.stripe_secret'))
            ->withHeaders([
                'Idempotency-Key' => hash('sha256', 'oneploy:stripe:'.$checkout->uuid),
            ])
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([200, 500, 1000], function (Throwable $exception): bool {
                return ! ($exception instanceof RequestException) || $exception->response->serverError();
            }, throw: false);
    }
}
