<?php

namespace App\Services\OnePloy;

use App\Models\OneployCheckoutSession;
use App\Models\OneployDomain;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DomainCheckoutService
{
    public function __construct(
        private readonly ConnectResellerClient $registrar,
        private readonly CheckoutService $checkout,
        private readonly PowerDnsClient $powerDns,
    ) {}

    /** @return array{currency: string, unit_amount_minor: int, amount_minor: int, years: int}|null */
    public function quote(string $domain, ?string $currency = null, int $years = 1): ?array
    {
        $domain = strtolower(trim($domain));
        $currency = strtoupper($currency ?: (string) config('oneploy.domains.default_currency', 'USD'));
        $prices = config('oneploy.domains.retail_prices', []);

        if (! is_array($prices) || $years < 1 || $years > 10) {
            return null;
        }

        $extensions = collect(array_keys($prices))
            ->map(fn (string $extension): string => ltrim(strtolower($extension), '.'))
            ->sortByDesc(fn (string $extension): int => strlen($extension));
        $extension = $extensions->first(fn (string $candidate): bool => str_ends_with($domain, '.'.$candidate));
        $unitAmount = $extension ? ($prices[$extension][$currency] ?? null) : null;

        if (! is_int($unitAmount) && ! ctype_digit((string) $unitAmount)) {
            return null;
        }

        $unitAmount = (int) $unitAmount;
        if ($unitAmount < 1) {
            return null;
        }

        return [
            'currency' => $currency,
            'unit_amount_minor' => $unitAmount,
            'amount_minor' => $unitAmount * $years,
            'years' => $years,
        ];
    }

    /**
     * @param  array{name: string, email: string, company: string, address: string, city: string, state: string, country: string, postal_code: string, phone_country_code: string, phone: string, consented_at?: string, consented_by?: int|null}  $registrant
     * @return array{domain: OneployDomain, checkout: OneployCheckoutSession, approval_url: string}
     */
    public function start(
        Team $team,
        int $userId,
        string $domain,
        array $registrant,
        ?string $currency = null,
        int $years = 1,
        bool $privacy = true,
        ?string $idempotencyKey = null,
    ): array {
        $domain = strtolower(trim($domain));
        $quote = $this->quote($domain, $currency, $years);
        if (! $quote) {
            throw new RuntimeException('This domain extension and currency do not have an active retail price.');
        }
        if (! $this->registrar->isConfigured()) {
            throw new RuntimeException('ConnectReseller must be configured before domain checkout is enabled.');
        }

        if (! $this->powerDns->isConfigured()) {
            $message = config('oneploy.dns.require_ha', false)
                ? 'Highly available authoritative DNS must be configured before domain checkout is enabled.'
                : 'Authoritative DNS must be configured before domain checkout is enabled.';

            throw new RuntimeException($message);
        }

        $nameservers = array_values(config('oneploy.dns.nameservers', []));
        if (count($nameservers) < 2) {
            throw new RuntimeException('At least two authoritative nameservers must be configured before domain checkout is enabled.');
        }

        $availability = $this->registrar->availability($domain);
        if (data_get($availability, 'available') !== true) {
            throw new RuntimeException('This domain is no longer available to register.');
        }
        if (data_get($availability, 'premium') === true) {
            throw new RuntimeException('Premium domains require an operator-reviewed quote and cannot use automatic checkout.');
        }

        try {
            [$registration, $session] = DB::transaction(function () use (
                $team,
                $userId,
                $domain,
                $registrant,
                $quote,
                $years,
                $privacy,
                $nameservers,
                $idempotencyKey,
            ): array {
                $existing = OneployDomain::query()
                    ->with('checkoutSession')
                    ->where('name', $domain)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $existingCheckout = $existing->checkoutSession;
                    $canResume = $existing->team_id === $team->id
                        && $existing->status === 'pending_payment'
                        && $existingCheckout
                        && in_array($existingCheckout->status, ['open', 'pending_provider'], true)
                        && ! $existingCheckout->expires_at?->isPast();

                    if ($canResume) {
                        return [$existing, $existingCheckout];
                    }

                    if ($existing->status === 'pending_payment' && $existingCheckout?->expires_at?->isPast()) {
                        $existing->delete();
                    } else {
                        throw new RuntimeException('This domain already has an active checkout or registration in OnePloy.');
                    }
                }

                $session = OneployCheckoutSession::create([
                    'team_id' => $team->id,
                    'user_id' => $userId,
                    'status' => 'open',
                    'currency' => $quote['currency'],
                    'idempotency_key' => $idempotencyKey
                        ? 'domain:'.$team->id.':'.hash('sha256', $idempotencyKey)
                        : 'domain:'.$team->id.':'.Str::uuid(),
                    'items' => [[
                        'type' => 'domain_registration',
                        'domain' => $domain,
                        'registrar' => 'connectreseller',
                        'years' => $years,
                        'privacy' => $privacy,
                        'nameservers' => $nameservers,
                        'unit_amount_minor' => $quote['unit_amount_minor'],
                        'amount_minor' => $quote['amount_minor'],
                    ]],
                    'amount_minor' => $quote['amount_minor'],
                    'expires_at' => now()->addMinutes(30),
                ]);

                $registration = OneployDomain::create([
                    'team_id' => $team->id,
                    'checkout_session_id' => $session->id,
                    'name' => $domain,
                    'status' => 'pending_payment',
                    'registrar' => 'connectreseller',
                    'currency' => $quote['currency'],
                    'amount_minor' => $quote['amount_minor'],
                    'years' => $years,
                    'privacy' => $privacy,
                    'auto_renew' => true,
                    'nameservers' => $nameservers,
                    'contacts' => null,
                    'contact_payload' => $registrant,
                ]);

                return [$registration, $session];
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw new RuntimeException('This domain already has an active checkout or registration in OnePloy.', previous: $exception);
            }

            throw $exception;
        }

        $approvalUrl = $this->checkout->startPayPal(
            $session,
            route('oneploy.paypal.return'),
            route('oneploy.paypal.cancel'),
        );

        return [
            'domain' => $registration->fresh(),
            'checkout' => $session->fresh(),
            'approval_url' => $approvalUrl,
        ];
    }
}
