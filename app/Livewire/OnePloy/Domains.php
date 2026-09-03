<?php

namespace App\Livewire\OnePloy;

use App\Models\OneployDnsZone;
use App\Models\OneployDomain;
use App\Services\OnePloy\ConnectResellerClient;
use App\Services\OnePloy\PowerDnsClient;
use Livewire\Component;
use Throwable;

class Domains extends Component
{
    public string $query = '';

    public ?array $result = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function search(ConnectResellerClient $client): void
    {
        $validated = $this->validate([
            'query' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
        ]);
        $domain = strtolower(trim($validated['query']));
        $this->result = [
            'availability' => $client->availability($domain),
            'suggestions' => $client->suggest($domain),
        ];
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

    public function render(PowerDnsClient $powerDns)
    {
        return view('livewire.oneploy.domains', [
            'domains' => OneployDomain::query()->with('dnsZone')->where('team_id', currentTeam()?->id)->latest()->get(),
            'powerDnsConfigured' => $powerDns->isConfigured(),
        ]);
    }
}
