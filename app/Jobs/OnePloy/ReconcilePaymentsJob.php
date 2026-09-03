<?php

namespace App\Jobs\OnePloy;

use App\Models\OneployCheckoutSession;
use App\Services\OnePloy\CheckoutService;
use App\Services\OnePloy\PayPalClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePaymentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 240;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(PayPalClient $payPal, CheckoutService $checkout): void
    {
        if (! $payPal->isConfigured()) {
            return;
        }

        OneployCheckoutSession::query()
            ->where('provider', 'paypal')
            ->where('status', 'pending_provider')
            ->whereNotNull('provider_reference')
            ->oldest('id')
            ->limit(100)
            ->get()
            ->each(function (OneployCheckoutSession $session) use ($payPal, $checkout): void {
                if ($session->expires_at?->isPast()) {
                    $session->update(['status' => 'expired']);

                    return;
                }

                try {
                    $order = $payPal->order((string) $session->provider_reference);
                    $status = strtoupper((string) data_get($order, 'status', ''));

                    if ($status === 'APPROVED') {
                        $checkout->completePayPal($session, (string) $session->provider_reference);

                        return;
                    }

                    if ($status !== 'COMPLETED') {
                        return;
                    }

                    $payment = $payPal->paymentData($order);
                    $checkout->assertPaymentMatches($session, $payment);
                    $checkout->markPaid(
                        $session,
                        'paypal',
                        (string) $payment['provider_reference'],
                        [
                            'event_id' => $session->provider_reference,
                            'event_type' => 'PAYPAL.RECONCILIATION',
                            'order_status' => $status,
                        ],
                    );
                } catch (Throwable $exception) {
                    $session->update(['failure_reason' => 'Automatic PayPal reconciliation is pending.']);
                    report($exception);
                    Log::warning('oneploy.paypal.reconciliation_failed', [
                        'checkout_id' => $session->uuid,
                        'order_id' => $session->provider_reference,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('oneploy.paypal.reconciliation_job_failed', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
