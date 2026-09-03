<div>
    <x-dashboard.navbar section="billing" title="Billing"
        subtitle="Choose a plan, complete payment with PayPal, and review the resulting orders for this team." />

    <div class="mt-6 grid gap-6">
        @if (session('status'))
            <div class="rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->has('payment'))
            <div class="rounded-lg border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300">
                {{ $errors->first('payment') }}
            </div>
        @endif

        <x-application.settings-section title="Plan status" flush>
            @if ($subscriptions->isEmpty())
                <x-empty title="No paid plan yet"
                    description="Your self-hosted workspace remains available. Choose a plan below when you are ready to activate commercial entitlements."
                    size="sm" icon-name="subscription" />
            @else
                <div class="divide-y divide-neutral-200 dark:divide-white/[0.08]">
                    @foreach ($subscriptions as $subscription)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="subscription-{{ $subscription->id }}">
                            <div>
                                <p class="text-sm font-medium text-black dark:text-white">
                                    {{ $subscription->product?->name ?? 'OnePloy product' }}
                                </p>
                                <p class="mt-0.5 text-xs text-neutral-500">
                                    {{ $subscription->planVersion?->plan?->name ?? 'Plan' }}
                                    @if ($subscription->current_period_ends_at)
                                        · Renews or ends {{ $subscription->current_period_ends_at->toFormattedDateString() }}
                                    @endif
                                </p>
                            </div>
                            <x-status-badge :status="$subscription->status"
                                :type="$subscription->status === 'active' ? 'success' : 'warning'" />
                        </div>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>

        <x-application.settings-section title="Available plans"
            description="Checkout opens on PayPal and the server verifies the capture before enabling entitlements." flush>
            <x-slot:actions>
                <x-status-badge :status="$paypalConfigured ? 'PayPal ready' : 'PayPal setup required'"
                    :type="$paypalConfigured ? 'success' : 'warning'" />
            </x-slot:actions>

            <div class="divide-y divide-neutral-200 dark:divide-white/[0.08]">
                @foreach ($catalogue as $product)
                    <div class="px-4 py-4" wire:key="product-{{ $product['slug'] }}">
                        <div class="mb-3">
                            <h2 class="text-sm font-semibold text-black dark:text-white">{{ $product['name'] }}</h2>
                            <p class="mt-1 text-xs text-neutral-500">{{ $product['description'] }}</p>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($product['plans'] as $plan)
                                <div class="flex min-h-16 items-center justify-between gap-3 rounded-lg bg-neutral-100 px-3 py-2.5 dark:bg-white/[0.04]"
                                    wire:key="plan-{{ $product['slug'] }}-{{ $plan['slug'] }}">
                                    <div>
                                        <p class="text-sm font-medium">{{ $plan['name'] }}</p>
                                        <p class="mt-0.5 text-xs text-neutral-500">
                                            {{ $plan['price']['formatted'] ?? 'Contact sales' }}
                                            @if ($plan['price'])
                                                / {{ $plan['price']['interval'] }}
                                            @endif
                                        </p>
                                    </div>
                                    @if ($plan['price'])
                                        <x-forms.button wire:click="startCheckout({{ $plan['price']['id'] }})"
                                            wire:target="startCheckout({{ $plan['price']['id'] }})"
                                            :disabled="! $paypalConfigured"
                                            :tooltip="$paypalConfigured ? null : 'Add PayPal client ID, secret, and webhook ID to enable checkout.'">
                                            Pay with PayPal
                                        </x-forms.button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Recent checkout activity" flush>
            @if ($checkouts->isEmpty())
                <x-empty title="No checkout activity" description="New checkout attempts will appear here." size="sm"
                    icon-name="time-back" />
            @else
                <div class="divide-y divide-neutral-200 text-sm dark:divide-white/[0.08]">
                    @foreach ($checkouts as $checkout)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="checkout-{{ $checkout->uuid }}">
                            <div>
                                <p class="font-medium">{{ $checkout->currency }} {{ number_format($checkout->amount_minor / 100, 2) }}</p>
                                <p class="mt-0.5 text-xs text-neutral-500">{{ $checkout->created_at->toDayDateTimeString() }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($checkout->status === 'pending_provider' && $checkout->approval_url)
                                    <a class="button" href="{{ $checkout->approval_url }}">Resume payment</a>
                                @endif
                                <x-status-badge :status="str_replace('_', ' ', $checkout->status)"
                                    :type="$checkout->status === 'paid' ? 'success' : ($checkout->status === 'pending_provider' ? 'warning' : 'neutral')" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>

        @if ($orders->isNotEmpty())
            <x-application.settings-section title="Paid orders" flush>
                <div class="divide-y divide-neutral-200 text-sm dark:divide-white/[0.08]">
                    @foreach ($orders as $order)
                        <div class="flex items-center justify-between gap-3 px-4 py-3" wire:key="order-{{ $order->uuid }}">
                            <div>
                                <p class="font-medium">{{ $order->currency }} {{ number_format($order->amount_minor / 100, 2) }}</p>
                                <p class="mt-0.5 text-xs text-neutral-500">Order {{ $order->uuid }}</p>
                            </div>
                            <x-status-badge :status="$order->status" :type="$order->status === 'paid' ? 'success' : 'warning'" />
                        </div>
                    @endforeach
                </div>
            </x-application.settings-section>
        @endif
    </div>
</div>
