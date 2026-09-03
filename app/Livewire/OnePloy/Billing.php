<?php

namespace App\Livewire\OnePloy;

use App\Models\OneployCheckoutSession;
use App\Models\OneployCommerceSubscription;
use App\Models\OneployOrder;
use App\Services\OnePloy\CatalogService;
use App\Services\OnePloy\CheckoutService;
use App\Services\OnePloy\PayPalClient;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Throwable;

class Billing extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function startCheckout(int $priceId, CheckoutService $checkout): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before starting checkout.');

        try {
            $session = $checkout->create([
                'price_id' => $priceId,
                'idempotency_key' => 'plan:'.$team->id.':'.$priceId.':'.now()->format('YmdHi'),
            ], $team, auth()->id());
            $approvalUrl = $checkout->startPayPal(
                $session,
                route('oneploy.paypal.return'),
                route('oneploy.paypal.cancel'),
            );
            $this->redirect($approvalUrl);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('oneploy.checkout.start_failed', [
                'team_id' => $team->id,
                'price_id' => $priceId,
                'error' => $exception->getMessage(),
            ]);
            $this->dispatch('error', 'Checkout could not start. Confirm the PayPal credentials and try again.');
        }
    }

    public function render(CatalogService $catalog, PayPalClient $payPal)
    {
        $team = currentTeam();

        return view('livewire.oneploy.billing', [
            'subscriptions' => $team ? OneployCommerceSubscription::query()
                ->with(['product', 'planVersion.plan'])
                ->where('team_id', $team->id)
                ->latest()
                ->get() : collect(),
            'orders' => $team ? OneployOrder::query()->where('team_id', $team->id)->latest()->limit(20)->get() : collect(),
            'checkouts' => $team ? OneployCheckoutSession::query()->where('team_id', $team->id)->latest()->limit(10)->get() : collect(),
            'catalogue' => $catalog->catalogue(),
            'paypalConfigured' => $payPal->isConfigured(),
        ]);
    }
}
