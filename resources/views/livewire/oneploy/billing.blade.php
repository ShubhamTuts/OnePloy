<div>
    <x-dashboard.navbar section="billing" title="Billing" subtitle="Plans and prices come from the OnePloy catalog. Payment providers need live keys before checkout can collect money." />
    @if ($subscription)
        <p class="mb-4 text-sm">Active plan version #{{ $subscription->plan_version_id }} · {{ $subscription->status }}</p>
    @else
        <p class="mb-4 text-sm text-neutral-500">No OnePloy subscription on this team yet. Self-hosted workspace still works.</p>
    @endif
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($catalogue as $product)
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-white/10">
                <h2 class="font-semibold">{{ $product['name'] }}</h2>
                <p class="mt-1 text-sm text-neutral-500">{{ $product['description'] }}</p>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($product['plans'] as $plan)
                        <li>{{ $plan['name'] }} — {{ $plan['price']['formatted'] ?? 'contact sales' }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</div>
