<?php

namespace App\Livewire\OnePloy;

use App\Models\OneployDnsZone;
use App\Models\OneployDomain;
use App\Services\OnePloy\ConnectResellerClient;
use App\Services\OnePloy\DomainCheckoutService;
use App\Services\OnePloy\PowerDnsClient;
use Livewire\Component;
use RuntimeException;
use Throwable;

class Domains extends Component
{
    public string $query = '';

    public ?array $result = null;

    public string $currency = 'USD';

    public int $years = 1;

    public bool $privacy = true;

    public string $registrantName = '';

    public string $registrantEmail = '';

    public string $registrantCompany = '';

    public string $registrantAddress = '';

    public string $registrantCity = '';

    public string $registrantState = '';

    public string $registrantCountry = '';

    public string $registrantPostalCode = '';

    public string $registrantPhoneCountryCode = '';

    public string $registrantPhone = '';

    public bool $registrantConsent = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $marketingDomain = trim((string) request()->query('domain'));
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $marketingDomain) === 1) {
            $this->query = strtolower($marketingDomain);
        }
        $this->currency = strtoupper((string) config('oneploy.domains.default_currency', 'USD'));
        $this->registrantName = (string) auth()->user()?->name;
        $this->registrantEmail = (string) auth()->user()?->email;
    }

    public function search(ConnectResellerClient $client, DomainCheckoutService $domainCheckout): void
    {
        $validated = $this->validate([
            'query' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
        ]);
        $domain = strtolower(trim($validated['query']));
        try {
            $this->result = [
                'availability' => $client->availability($domain),
                'suggestions' => $client->suggest($domain),
                'quote' => $domainCheckout->quote($domain, $this->currency, $this->years),
            ];
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('query', 'The registrar is temporarily unavailable. Please try again.');
        }
    }

    public function purchase(DomainCheckoutService $domainCheckout): mixed
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before starting checkout.');

        $validated = $this->validate([
            'query' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'currency' => ['required', 'string', 'size:3'],
            'years' => ['required', 'integer', 'min:1', 'max:10'],
            'privacy' => ['boolean'],
            'registrantName' => ['required', 'string', 'max:100'],
            'registrantEmail' => ['required', 'email:rfc', 'max:255'],
            'registrantCompany' => ['nullable', 'string', 'max:100'],
            'registrantAddress' => ['required', 'string', 'max:255'],
            'registrantCity' => ['required', 'string', 'max:100'],
            'registrantState' => ['required', 'string', 'max:100'],
            'registrantCountry' => ['required', 'string', 'max:100'],
            'registrantPostalCode' => ['required', 'string', 'max:20'],
            'registrantPhoneCountryCode' => ['required', 'string', 'regex:/^\+?[0-9]{1,4}$/'],
            'registrantPhone' => ['required', 'string', 'regex:/^[0-9][0-9 -]{5,19}$/'],
            'registrantConsent' => ['accepted'],
        ]);

        try {
            $purchase = $domainCheckout->start(
                team: $team,
                userId: auth()->id(),
                domain: $validated['query'],
                registrant: [
                    'name' => $validated['registrantName'],
                    'email' => $validated['registrantEmail'],
                    'company' => filled($validated['registrantCompany']) ? $validated['registrantCompany'] : $validated['registrantName'],
                    'address' => $validated['registrantAddress'],
                    'city' => $validated['registrantCity'],
                    'state' => $validated['registrantState'],
                    'country' => $validated['registrantCountry'],
                    'postal_code' => $validated['registrantPostalCode'],
                    'phone_country_code' => $validated['registrantPhoneCountryCode'],
                    'phone' => $validated['registrantPhone'],
                    'consented_at' => now()->toIso8601String(),
                    'consented_by' => auth()->id(),
                ],
                currency: $validated['currency'],
                years: $validated['years'],
                privacy: $validated['privacy'],
            );

            return redirect($purchase['approval_url'], 303);
        } catch (RuntimeException $exception) {
            $this->addError('purchase', $exception->getMessage());

            return null;
        }
    }

    public function activateDns(int $domainId, PowerDnsClient $powerDns): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $team = currentTeam();
        abort_unless($team, 403, 'Select a team before managing DNS.');

        $domain = OneployDomain::query()
            ->where('team_id', $team->id)
            ->whereKey($domainId)
            ->whereIn('status', ['active', 'registered'])
            ->firstOrFail();

        try {
            $zone = $powerDns->ensureZone($domain->name);
            OneployDnsZone::query()->updateOrCreate(
                ['team_id' => $team->id, 'domain_id' => $domain->id],
                [
                    'name' => $domain->name,
                    'status' => 'active',
                    'records' => data_get($zone, 'rrsets', []),
                    'dnssec' => (bool) data_get($zone, 'dnssec', false),
                ],
            );
            $this->dispatch('success', 'Authoritative DNS is active for '.$domain->name.'.');
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('dns', 'PowerDNS could not create this zone. Check the API URL, key, and nameserver configuration.');
        }
    }

    public function render(PowerDnsClient $powerDns, DomainCheckoutService $domainCheckout)
    {
        return view('livewire.oneploy.domains', [
            'domains' => OneployDomain::query()->with('dnsZone')->where('team_id', currentTeam()?->id)->latest()->get(),
            'powerDnsConfigured' => $powerDns->isConfigured(),
            'registrarConfigured' => app(ConnectResellerClient::class)->isConfigured(),
            'currencies' => config('oneploy.storefront.currencies', ['USD']),
            'currentQuote' => data_get($this->result, 'availability.available') === true
                ? $domainCheckout->quote($this->query, $this->currency, $this->years)
                : null,
        ]);
    }
}
