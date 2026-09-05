<div>
    <x-dashboard.navbar section="billing" title="Secure checkout"
        subtitle="Confirm the plan selected on our WordPress marketing site before continuing to the payment provider." />

    <div class="mx-auto mt-8 grid max-w-4xl gap-6 lg:grid-cols-[1fr_20rem]">
        <section class="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-purple-600 dark:text-purple-300">
                {{ $price->planVersion->plan->product->name }}
            </p>
            <h1 class="mt-2 text-2xl font-semibold text-neutral-950 dark:text-white">
                {{ $price->planVersion->plan->name }}
            </h1>
            <div class="mt-5 flex items-baseline gap-2">
                <span class="text-4xl font-semibold tracking-tight text-neutral-950 dark:text-white">
                    {{ $price->formatted() }}
                </span>
                <span class="text-sm text-neutral-500 dark:text-neutral-400">/{{ $price->interval }}</span>
            </div>

            @if ($price->planVersion->features)
                <ul class="mt-6 grid gap-3 text-sm text-neutral-700 dark:text-neutral-300">
                    @foreach ($price->planVersion->features as $feature)
                        <li class="flex items-start gap-2" wire:key="marketing-checkout-feature-{{ md5((string) $feature) }}">
                            <span aria-hidden="true" class="mt-0.5 text-emerald-600 dark:text-emerald-400">✓</span>
                            <span>{{ $feature }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <aside class="rounded-2xl border border-neutral-200 bg-neutral-50 p-6 dark:border-white/10 dark:bg-black/20">
            <h2 class="text-base font-semibold text-neutral-950 dark:text-white">Payment</h2>
            @if ($providers === [])
                <p class="mt-3 text-sm text-red-700 dark:text-red-300">
                    No verified payment provider is configured. Contact the OnePloy administrator.
                </p>
            @else
                <label for="marketing-checkout-provider" class="mt-4 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    Provider
                </label>
                <select id="marketing-checkout-provider" wire:model="provider"
                    class="mt-2 w-full rounded-lg border-neutral-300 bg-white text-sm text-neutral-950 dark:border-white/10 dark:bg-neutral-900 dark:text-white">
                    @foreach ($providers as $providerValue => $providerLabel)
                        <option value="{{ $providerValue }}">{{ $providerLabel }}</option>
                    @endforeach
                </select>
                @error('provider')
                    <p class="mt-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                @enderror

                <x-forms.button class="mt-5 w-full justify-center" wire:click="startCheckout" wire:target="startCheckout">
                    Continue to secure payment
                </x-forms.button>
            @endif

            @if ($returnUrl)
                <a href="{{ $returnUrl }}" class="mt-4 block text-center text-sm text-neutral-600 underline hover:text-neutral-950 dark:text-neutral-400 dark:hover:text-white">
                    Return to marketing site
                </a>
            @endif

            <p class="mt-5 text-xs leading-5 text-neutral-500 dark:text-neutral-400">
                Prices are revalidated by OnePloy. WordPress never receives your account token or payment credentials.
            </p>
        </aside>
    </div>
</div>
