<?php

namespace App\Contracts\OnePloy;

use App\Models\OneployCheckoutSession;

interface PaymentProviderClient
{
    public function provider(): string;

    public function isConfigured(): bool;

    /**
     * @return array{provider_reference: string, approval_url: ?string, status: string, public_payload: array<string, mixed>}
     */
    public function initiate(OneployCheckoutSession $checkout, string $returnUrl, string $cancelUrl): array;
}
