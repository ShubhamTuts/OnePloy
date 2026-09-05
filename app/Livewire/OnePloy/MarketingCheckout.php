<?php

namespace App\Livewire\OnePloy;

use App\Exceptions\OnePloy\PaymentInitiationException;
use App\Services\OnePloy\CheckoutService;
use App\Services\OnePloy\PaymentProviderManager;
use App\Services\OnePloy\WordPressMarketingHandoff;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

class MarketingCheckout extends Component
{
    #[Locked]
    public int $priceId;

    public string $provider = 'paypal';

    #[Locked]
    public ?string $returnUrl = null;

    /** @var array<string, string> */
    #[Locked]
    public array $attribution = [];

    public function mount(
        WordPressMarketingHandoff $handoff,
        PaymentProviderManager $paymentProviders,
    ): void {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $payload = $this->acceptedPayload($handoff);
        $this->applyPayload($payload);

        $configuredProviders = $this->configuredProviders($paymentProviders);
        $requestedProvider = (string) $payload['provider'];
        $this->provider = in_array($requestedProvider, $configuredProviders, true)
            ? $requestedProvider
            : ($configuredProviders[0] ?? $requestedProvider);
    }

    public function startCheckout(
        CheckoutService $checkout,
        PaymentProviderManager $paymentProviders,
        WordPressMarketingHandoff $handoff,
    ): void {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->validate(['provider' => ['required', 'in:paypal,stripe,razorpay']]);
        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before starting checkout.');
        $payload = $this->acceptedPayload($handoff);
        $this->applyPayload($payload);

        $provider = $paymentProviders->provider($this->provider);
        if (! $provider->isConfigured()) {
            $this->addError('provider', ucfirst($this->provider).' is not configured for verified payments.');

            return;
        }
        if (! $handoff->reserve($payload, $team->id, (int) auth()->id())) {
            $this->addError('provider', 'This checkout link is already being used by another account. Return to the marketing site and start again.');

            return;
        }

        try {
            $session = $checkout->create([
                'price_id' => $this->priceId,
                'idempotency_key' => 'wordpress:'.$team->id.':'.hash('sha256', $this->attribution['handoff_nonce']),
                'attribution' => $this->attribution,
            ], $team, auth()->id());
            $session = $checkout->startPayment(
                $session,
                $this->provider,
                $this->paymentReturnUrl(),
                $this->paymentCancelUrl(),
            );

            if (filled($session->approval_url)) {
                $this->redirect((string) $session->approval_url);

                return;
            }

            if ($this->provider === 'razorpay') {
                $this->dispatch('oneploy:razorpay-ready', checkout: [
                    ...$session->provider_payload,
                    'name' => (string) auth()->user()?->name,
                    'email' => (string) auth()->user()?->email,
                    'description' => (string) data_get($session->items, '0.plan_name', 'OnePloy order'),
                    'return_url' => route('oneploy.billing').'?checkout=pending&provider=razorpay',
                ]);

                return;
            }

            throw new PaymentInitiationException;
        } catch (PaymentInitiationException|RuntimeException $exception) {
            $this->addError('provider', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('oneploy.wordpress_checkout.start_failed', [
                'team_id' => $team->id,
                'price_id' => $this->priceId,
                'provider' => $this->provider,
                'exception' => $exception::class,
            ]);
            $this->addError('provider', 'Checkout could not start. Please try again.');
        }
    }

    public function render(WordPressMarketingHandoff $handoff, PaymentProviderManager $paymentProviders): View
    {
        $price = $handoff->activePrice($this->priceId);
        abort_unless($price, 404);

        $providers = collect($this->configuredProviders($paymentProviders))
            ->mapWithKeys(fn (string $provider): array => [$provider => ucfirst($provider)])
            ->all();

        return view('livewire.oneploy.marketing-checkout', [
            'price' => $price,
            'providers' => $providers,
        ]);
    }

    private function paymentReturnUrl(): string
    {
        return $this->provider === 'paypal'
            ? route('oneploy.paypal.return')
            : route('oneploy.billing').'?checkout=success';
    }

    private function paymentCancelUrl(): string
    {
        return $this->provider === 'paypal'
            ? route('oneploy.paypal.cancel')
            : ($this->returnUrl ?? route('oneploy.billing').'?checkout=cancelled');
    }

    /** @return array{price_id: int, provider: string, issued_at: int, nonce: string, key_id: string, source: string, campaign: string, return_url: string|null} */
    private function acceptedPayload(WordPressMarketingHandoff $handoff): array
    {
        $payload = session(WordPressMarketingHandoff::SESSION_KEY);
        abort_unless(is_array($payload), 404);

        try {
            return $handoff->revalidate($payload);
        } catch (InvalidArgumentException) {
            session()->forget(WordPressMarketingHandoff::SESSION_KEY);
            abort(403, 'This WordPress checkout link has expired. Return to the marketing site and try again.');
        }
    }

    /** @param array{price_id: int, provider: string, issued_at: int, nonce: string, key_id: string, source: string, campaign: string, return_url: string|null} $payload */
    private function applyPayload(array $payload): void
    {
        $this->priceId = $payload['price_id'];
        $this->returnUrl = $payload['return_url'];
        $this->attribution = [
            'source' => $payload['source'],
            'campaign' => $payload['campaign'],
            'handoff_nonce' => $payload['nonce'],
        ];
    }

    /** @return list<string> */
    private function configuredProviders(PaymentProviderManager $paymentProviders): array
    {
        return collect(['paypal', 'stripe', 'razorpay'])
            ->filter(fn (string $provider): bool => $paymentProviders->provider($provider)->isConfigured())
            ->values()
            ->all();
    }
}
