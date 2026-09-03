<div>
    <x-dashboard.navbar section="domains" title="Domains"
        subtitle="Search, purchase, register, and operate authoritative DNS from one workflow." />

    <div class="mt-6 grid gap-6">
        <x-application.settings-section title="Register a domain"
            description="Availability is checked live. Registration begins only after PayPal confirms the exact payment." flush>
            <div class="max-w-4xl space-y-5 p-4">
                <form wire:submit="search" class="flex flex-col gap-2 sm:flex-row">
                    <input wire:model="query" class="input w-full" placeholder="example.com" autocomplete="off" />
                    <x-forms.button type="submit" wire:target="search">Check availability</x-forms.button>
                </form>
                @error('query')
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                @if (!$registrarConfigured)
                    <p class="rounded-lg border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-200">
                        Domain search and checkout unlock after the ConnectReseller API key is added by the platform operator.
                    </p>
                @endif

                @if ($result)
                    @php($availability = $result['availability'])
                    <div class="flex flex-wrap items-center justify-between gap-3 border-y border-neutral-200 py-4 dark:border-white/[0.08]">
                        <div>
                            <p class="text-base font-semibold">{{ $availability['domain'] }}</p>
                            <p class="mt-0.5 text-xs text-neutral-500">
                                {{ $availability['message'] ?? ($availability['available'] === true ? 'Available to register.' : ($availability['available'] === false ? 'Already registered or unavailable.' : 'Registrar setup is required.')) }}
                            </p>
                        </div>
                        <x-status-badge
                            :status="$availability['available'] === true ? 'Available' : ($availability['available'] === false ? 'Unavailable' : 'Setup required')"
                            :type="$availability['available'] === true ? 'success' : ($availability['available'] === false ? 'error' : 'warning')" />
                    </div>

                    @if ($availability['available'] === true)
                        @if ($availability['premium'] ?? false)
                            <p class="text-sm text-amber-700 dark:text-amber-300">Premium domains need an operator-reviewed quote before payment.</p>
                        @elseif (!$currentQuote)
                            <p class="text-sm text-amber-700 dark:text-amber-300">This extension does not have a retail price in {{ $currency }} yet.</p>
                        @else
                            <form wire:submit="purchase" class="space-y-5">
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <x-forms.select id="years" label="Registration term" required>
                                        @foreach (range(1, 10) as $year)
                                            <option value="{{ $year }}">{{ $year }} {{ $year === 1 ? 'year' : 'years' }}</option>
                                        @endforeach
                                    </x-forms.select>
                                    <x-forms.select id="currency" label="Currency" required>
                                        @foreach ($currencies as $supportedCurrency)
                                            <option value="{{ $supportedCurrency }}">{{ $supportedCurrency }}</option>
                                        @endforeach
                                    </x-forms.select>
                                    <div class="flex flex-col justify-end">
                                        <p class="text-xs text-neutral-500">Total due now</p>
                                        <p class="text-2xl font-semibold tracking-tight">
                                            {{ $currentQuote['currency'] }} {{ number_format($currentQuote['amount_minor'] / 100, 2) }}
                                        </p>
                                    </div>
                                </div>

                                <div class="border-t border-neutral-200 pt-5 dark:border-white/[0.08]">
                                    <p class="mb-3 text-sm font-semibold">Registrant contact</p>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <x-forms.input id="registrantName" label="Full name" required autocomplete="name" />
                                        <x-forms.input id="registrantEmail" type="email" label="Email" required autocomplete="email" />
                                        <x-forms.input id="registrantCompany" label="Company (optional)" autocomplete="organization" />
                                        <x-forms.input id="registrantAddress" label="Street address" required autocomplete="street-address" />
                                        <x-forms.input id="registrantCity" label="City" required autocomplete="address-level2" />
                                        <x-forms.input id="registrantState" label="State / province" required autocomplete="address-level1" />
                                        <x-forms.input id="registrantCountry" label="Country" required autocomplete="country-name" />
                                        <x-forms.input id="registrantPostalCode" label="Postal code" required autocomplete="postal-code" />
                                        <x-forms.input id="registrantPhoneCountryCode" label="Phone country code" required placeholder="+91" />
                                        <x-forms.input id="registrantPhone" label="Phone number" required autocomplete="tel-national" />
                                    </div>
                                </div>

                                <div class="space-y-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                    <x-forms.checkbox id="privacy" label="Request WHOIS privacy when the selected TLD supports it." />
                                    <x-forms.checkbox id="registrantConsent" fullWidth
                                        label="I authorize OnePloy to send this contact information and domain request to ConnectReseller for registration." />
                                    @error('registrantConsent')
                                        <p class="text-xs text-red-600 dark:text-red-400">Consent is required before registry data can be submitted.</p>
                                    @enderror
                                    @error('purchase')
                                        <p class="rounded-lg border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="max-w-xl text-xs text-neutral-500">The price and availability are rechecked before PayPal opens. Payment success queues registration automatically.</p>
                                    <x-forms.button type="submit" wire:target="purchase">Continue to PayPal</x-forms.button>
                                </div>
                            </form>
                        @endif
                    @endif
                @endif
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Your domains" flush>
            <x-slot:actions>
                <x-status-badge :status="$powerDnsConfigured ? 'PowerDNS ready' : 'PowerDNS setup required'"
                    :type="$powerDnsConfigured ? 'success' : 'warning'" />
            </x-slot:actions>

            @error('dns')
                <p class="m-4 rounded-lg border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
            @enderror

            @if ($domains->isEmpty())
                <x-empty title="No domains yet"
                    description="A paid registration appears here immediately and advances as payment, registrar, and DNS steps finish." size="sm"
                    icon-name="globe" />
            @else
                <div class="divide-y divide-neutral-200 dark:divide-white/[0.08]">
                    @foreach ($domains as $domain)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="domain-{{ $domain->id }}">
                            <div>
                                <p class="text-sm font-medium">{{ $domain->name }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <x-status-badge :status="str($domain->status)->replace('_', ' ')->title()"
                                        :type="in_array($domain->status, ['active', 'registered'], true) ? 'success' : ($domain->status === 'manual_review' ? 'error' : 'warning')" />
                                    @if ($domain->dnsZone)
                                        <x-status-badge status="Authoritative DNS active" type="success" />
                                    @endif
                                </div>
                                @if ($domain->last_error)
                                    <p class="mt-1 max-w-2xl text-xs {{ $domain->status === 'manual_review' ? 'text-red-600 dark:text-red-300' : 'text-amber-700 dark:text-amber-300' }}">
                                        {{ $domain->last_error }}
                                    </p>
                                @endif
                            </div>
                            @if (in_array($domain->status, ['active', 'registered'], true) && ! $domain->dnsZone)
                                <x-forms.button wire:click="activateDns({{ $domain->id }})"
                                    wire:target="activateDns({{ $domain->id }})"
                                    :disabled="!$powerDnsConfigured"
                                    :tooltip="$powerDnsConfigured ? null : 'Configure the PowerDNS API and at least two nameservers first.'">
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
