<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OneployCheckoutSession;
use App\Models\OneployMarketplaceApp;
use App\Services\OnePloy\CatalogService;
use App\Services\OnePloy\CheckoutService;
use App\Services\OnePloy\ConnectResellerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function domainSearch(Request $request, ConnectResellerClient $registrar): JsonResponse
    {
        $domain = strtolower(trim((string) $request->query('q', $request->query('domain'))));
        abort_if($domain === '', 422, 'Domain is required.');

        return response()->json([
            'availability' => $registrar->availability($domain),
            'suggestions' => $registrar->suggest($domain),
        ]);
    }

    public function checkout(Request $request, CheckoutService $checkout): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $data = $request->validate([
            'price_id' => 'required|integer|exists:oneploy_prices,id',
            'idempotency_key' => 'nullable|string|max:100',
            'locale' => 'nullable|string|max:16',
            'attribution' => 'nullable|array',
            'provider' => 'nullable|in:paypal',
        ]);

        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before starting checkout.');
        $session = $checkout->create($data, $team, $request->user()?->id);
        $approvalUrl = $checkout->startPayPal(
            $session,
            route('oneploy.paypal.return'),
            route('oneploy.paypal.cancel'),
        );

        return response()->json([
            'checkout' => [
                'id' => $session->uuid,
                'status' => $session->status,
                'currency' => $session->currency,
                'amount_minor' => $session->amount_minor,
                'provider' => 'paypal',
                'approval_url' => $approvalUrl,
                'expires_at' => $session->expires_at,
            ],
        ], 201);
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
}
