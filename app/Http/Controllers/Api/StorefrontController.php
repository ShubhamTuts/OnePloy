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
        $data = $request->validate([
            'price_id' => 'required|integer|exists:oneploy_prices,id',
            'idempotency_key' => 'nullable|string|max:100',
            'locale' => 'nullable|string|max:16',
            'coupon' => 'nullable|string|max:64',
            'attribution' => 'nullable|array',
        ]);

        $session = $checkout->create($data, currentTeam(), $request->user()?->id);

        return response()->json([
            'checkout' => [
                'id' => $session->uuid,
                'status' => $session->status,
                'currency' => $session->currency,
                'amount_minor' => $session->amount_minor,
                'expires_at' => $session->expires_at,
            ],
        ], 201);
    }

    public function checkoutStatus(string $uuid): JsonResponse
    {
        $session = OneployCheckoutSession::query()->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'id' => $session->uuid,
            'status' => $session->status,
            'currency' => $session->currency,
            'amount_minor' => $session->amount_minor,
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
