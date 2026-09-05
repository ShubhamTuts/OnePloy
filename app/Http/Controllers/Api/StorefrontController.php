<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OnePloy\PaymentInitiationException;
use App\Http\Controllers\Controller;
use App\Models\OneployCheckoutSession;
use App\Models\OneployDomain;
use App\Models\OneployMarketplaceApp;
use App\Services\OnePloy\CatalogService;
use App\Services\OnePloy\CheckoutService;
use App\Services\OnePloy\ConnectResellerClient;
use App\Services\OnePloy\DomainCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class StorefrontController extends Controller
{
    public function catalogue(Request $request, CatalogService $catalog): JsonResponse
    {
        return response()->json([
            'currencies' => config('oneploy.storefront.currencies'),
            'default_currency' => config('oneploy.storefront.default_currency'),
            'products' => $catalog->catalogue($request->query('currency'), $request->query('interval', 'monthly')),
        ])->header('Cache-Control', 'public, max-age=60');
    }

    public function applications(): JsonResponse
    {
        return response()->json([
            'applications' => OneployMarketplaceApp::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function domainSearch(
        Request $request,
        ConnectResellerClient $registrar,
        DomainCheckoutService $domainCheckout,
    ): JsonResponse {
        $domain = strtolower(trim((string) $request->query('q', $request->query('domain'))));
        Validator::make(['domain' => $domain], [
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
        ])->validate();
        $currency = strtoupper((string) $request->query('currency', config('oneploy.domains.default_currency')));
        $suggestions = $registrar->suggest($domain);

        return response()->json([
            'availability' => $registrar->availability($domain),
            'suggestions' => array_values((array) ($suggestions['suggestions'] ?? [])),
            'quote' => $domainCheckout->quote($domain, $currency),
        ]);
    }

    public function domainCheckout(Request $request, DomainCheckoutService $domainCheckout): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'currency' => ['nullable', 'string', 'size:3'],
            'years' => ['nullable', 'integer', 'min:1', 'max:10'],
            'privacy' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'registrant' => ['required', 'array'],
            'registrant.name' => ['required', 'string', 'max:100'],
            'registrant.email' => ['required', 'email:rfc', 'max:255'],
            'registrant.company' => ['nullable', 'string', 'max:100'],
            'registrant.address' => ['required', 'string', 'max:255'],
            'registrant.city' => ['required', 'string', 'max:100'],
            'registrant.state' => ['required', 'string', 'max:100'],
            'registrant.country' => ['required', 'string', 'max:100'],
            'registrant.postal_code' => ['required', 'string', 'max:20'],
            'registrant.phone_country_code' => ['required', 'string', 'regex:/^\+?[0-9]{1,4}$/'],
            'registrant.phone' => ['required', 'string', 'regex:/^[0-9][0-9 -]{5,19}$/'],
            'registrant.consent' => ['accepted'],
        ]);
        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before starting checkout.');

        $registrant = $data['registrant'];
        $registrant['company'] = filled($registrant['company'] ?? null)
            ? $registrant['company']
            : $registrant['name'];
        unset($registrant['consent']);
        $registrant['consented_at'] = now()->toIso8601String();
        $registrant['consented_by'] = $request->user()->id;

        try {
            $purchase = $domainCheckout->start(
                team: $team,
                userId: $request->user()->id,
                domain: $data['domain'],
                registrant: $registrant,
                currency: $data['currency'] ?? null,
                years: $data['years'] ?? 1,
                privacy: $data['privacy'] ?? true,
                idempotencyKey: $data['idempotency_key'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'domain' => [
                'id' => $purchase['domain']->uuid,
                'name' => $purchase['domain']->name,
                'status' => $purchase['domain']->status,
            ],
            'checkout' => [
                'id' => $purchase['checkout']->uuid,
                'status' => $purchase['checkout']->status,
                'currency' => $purchase['checkout']->currency,
                'amount_minor' => $purchase['checkout']->amount_minor,
                'provider' => 'paypal',
                'approval_url' => $purchase['approval_url'],
                'expires_at' => $purchase['checkout']->expires_at,
            ],
        ], 201);
    }

    public function domainStatus(Request $request, string $uuid): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before viewing a domain.');

        $domain = OneployDomain::query()
            ->with('dnsZone')
            ->where('team_id', $team->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'id' => $domain->uuid,
            'name' => $domain->name,
            'status' => $domain->status,
            'registrar' => $domain->registrar,
            'expires_at' => $domain->expires_at,
            'nameservers' => $domain->nameservers,
            'dns_active' => $domain->dnsZone?->status === 'active',
            'action_required' => in_array($domain->status, ['manual_review', 'dns_pending'], true)
                ? $domain->last_error
                : null,
        ]);
    }

    public function checkout(Request $request, CheckoutService $checkout): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $data = $request->validate([
            'price_id' => ['required', 'integer', 'exists:oneploy_prices,id'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'string', 'max:16'],
            'attribution' => ['nullable', 'array'],
            'provider' => ['nullable', 'in:paypal,stripe,razorpay'],
        ]);

        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before starting checkout.');
        $provider = strtolower((string) ($data['provider'] ?? config('oneploy.payments.default_provider')));

        try {
            $session = $checkout->create($data, $team, $request->user()?->id);
            $session = $checkout->startPayment(
                $session,
                $provider,
                $this->paymentReturnUrl($provider),
                $this->paymentCancelUrl($provider),
            );
        } catch (PaymentInitiationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'checkout' => $this->checkoutPayload($session),
        ], 201);
    }

    public function initiatePayment(Request $request, string $uuid, CheckoutService $checkout): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate([
            'provider' => ['required', 'in:paypal,stripe,razorpay'],
        ]);
        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before starting checkout.');
        $session = OneployCheckoutSession::query()
            ->where('team_id', $team->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
        $provider = strtolower($data['provider']);

        try {
            $session = $checkout->startPayment(
                $session,
                $provider,
                $this->paymentReturnUrl($provider),
                $this->paymentCancelUrl($provider),
            );
        } catch (PaymentInitiationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['checkout' => $this->checkoutPayload($session)]);
    }

    public function checkoutStatus(Request $request, string $uuid): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before viewing checkout.');
        $session = OneployCheckoutSession::query()
            ->where('team_id', $team->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'id' => $session->uuid,
            'status' => $session->status,
            'currency' => $session->currency,
            'amount_minor' => $session->amount_minor,
            'provider' => $session->provider,
            'approval_url' => $session->status === 'pending_provider' ? $session->approval_url : null,
            'provider_data' => $session->status === 'pending_provider' ? $session->provider_payload : null,
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'platform' => 'OnePloy',
            'status' => 'ok',
            'payments' => config('oneploy.payments.default_provider'),
            'own_releases' => (bool) config('oneploy.own_releases'),
        ]);
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(OneployCheckoutSession $session): array
    {
        return [
            'id' => $session->uuid,
            'status' => $session->status,
            'currency' => $session->currency,
            'amount_minor' => $session->amount_minor,
            'provider' => $session->provider,
            'approval_url' => $session->approval_url,
            'provider_data' => $session->provider_payload,
            'expires_at' => $session->expires_at,
        ];
    }

    private function paymentReturnUrl(string $provider): string
    {
        return $provider === 'paypal'
            ? route('oneploy.paypal.return')
            : route('oneploy.billing').'?checkout=success';
    }

    private function paymentCancelUrl(string $provider): string
    {
        return $provider === 'paypal'
            ? route('oneploy.paypal.cancel')
            : route('oneploy.billing').'?checkout=cancelled';
    }
}
