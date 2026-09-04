<?php

namespace App\Services\OnePloy;

use App\Contracts\OnePloy\PaymentProviderClient;
use RuntimeException;

class PaymentProviderManager
{
    public function __construct(
        private readonly PayPalClient $payPal,
        private readonly StripeCheckoutClient $stripe,
        private readonly RazorpayClient $razorpay,
    ) {}

    public function provider(string $provider): PaymentProviderClient
    {
        return match ($provider) {
            'paypal' => $this->payPal,
            'stripe' => $this->stripe,
            'razorpay' => $this->razorpay,
            default => throw new RuntimeException('Unsupported payment provider.'),
        };
    }
}
