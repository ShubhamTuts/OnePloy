<?php

namespace App\Http\Controllers\OnePloy;

use App\Http\Controllers\Controller;
use App\Models\OneployCheckoutSession;
use App\Services\OnePloy\CheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayPalCheckoutController extends Controller
{
    public function complete(Request $request, CheckoutService $checkout): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $orderId = $request->string('token')->toString();
        abort_if($orderId === '', 422, 'Missing PayPal order.');

        $session = $this->sessionForCurrentTeam($orderId);

        try {
            $checkout->completePayPal($session, $orderId);

            return redirect()->route('oneploy.billing')->with('status', 'Payment received. Your OnePloy order is active.');
        } catch (Throwable $exception) {
            report($exception);
            $session->update(['failure_reason' => 'PayPal capture could not be verified. Reconciliation will retry automatically.']);
            Log::warning('oneploy.paypal.capture_failed', [
                'checkout_id' => $session->uuid,
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);

            return redirect()->route('oneploy.billing')->withErrors([
                'payment' => 'PayPal has not confirmed this payment yet. We will reconcile it automatically; you can safely retry later.',
            ]);
        }
    }

    public function cancel(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $orderId = $request->string('token')->toString();
        if ($orderId !== '') {
            $session = $this->sessionForCurrentTeam($orderId);
            if ($session->status !== 'paid') {
                $session->update(['status' => 'cancelled']);
            }
        }

        return redirect()->route('oneploy.billing')->withErrors(['payment' => 'PayPal checkout was cancelled.']);
    }

    private function sessionForCurrentTeam(string $orderId): OneployCheckoutSession
    {
        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before completing checkout.');

        return OneployCheckoutSession::query()
            ->where('team_id', $team->id)
            ->where('provider', 'paypal')
            ->where('provider_reference', $orderId)
            ->firstOrFail();
    }
}
