<div>
    <x-dashboard.navbar section="domains" title="Domains"
        subtitle="Check availability, track registrations, and activate authoritative PowerDNS zones." />

    <div class="mt-6 grid gap-6">
        <x-application.settings-section title="Find a domain"
            description="Availability comes directly from the configured registrar. A result is not a reservation." flush>
            <div class="max-w-2xl p-4">
                <form wire:submit="search" class="flex flex-col gap-2 sm:flex-row">
                    <input wire:model="query" class="input w-full" placeholder="example.com" autocomplete="off" />
                    <x-forms.button type="submit" wire:target="search">Check availability</x-forms.button>
                </form>
                @error('query')
                    <p class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                @if ($result)
                    @php($availability = $result['availability'])
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-neutral-100 px-3 py-3 dark:bg-white/[0.04]">
                        <div>
                            <p class="text-sm font-medium">{{ $availability['domain'] }}</p>
                            <p class="mt-0.5 text-xs text-neutral-500">
                                {{ $availability['message'] ?? ($availability['available'] === true ? 'Available to register.' : ($availability['available'] === false ? 'Already registered or unavailable.' : 'Registrar did not return a final result.')) }}
                            </p>
                        </div>
                        <x-status-badge
                            :status="$availability['available'] === true ? 'Available' : ($availability['available'] === false ? 'Unavailable' : 'Setup required')"
                            :type="$availability['available'] === true ? 'success' : ($availability['available'] === false ? 'error' : 'warning')" />
                    </div>
                @endif
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Registered domains" flush>
            <x-slot:actions>
                <x-status-badge :status="$powerDnsConfigured ? 'PowerDNS ready' : 'PowerDNS setup required'"
                    :type="$powerDnsConfigured ? 'success' : 'warning'" />
            </x-slot:actions>

            @error('dns')
                <p class="m-4 rounded-lg border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
            @enderror

            @if ($domains->isEmpty())
                <x-empty title="No registered domains"
                    description="Paid domain registration will add the domain here before DNS activation begins." size="sm"
                    icon-name="globe" />
            @else
                <div class="divide-y divide-neutral-200 dark:divide-white/[0.08]">
                    @foreach ($domains as $domain)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="domain-{{ $domain->id }}">
                            <div>
                                <p class="text-sm font-medium">{{ $domain->name }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <x-status-badge :status="$domain->status"
                                        :type="in_array($domain->status, ['active', 'registered'], true) ? 'success' : 'warning'" />
                                    @if ($domain->dnsZone)
                                        <x-status-badge status="Authoritative DNS active" type="success" />
                                    @endif
                                </div>
                            </div>
                            @if (in_array($domain->status, ['active', 'registered'], true) && ! $domain->dnsZone)
                                <x-forms.button wire:click="activateDns({{ $domain->id }})"
                                    wire:target="activateDns({{ $domain->id }})"
                                    :disabled="! $powerDnsConfigured"
                                    :tooltip="$powerDnsConfigured ? null : 'Add the PowerDNS API URL, key, and at least two nameservers first.'">
                                    Activate DNS
                                </x-forms.button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>
    </div>
</div>
