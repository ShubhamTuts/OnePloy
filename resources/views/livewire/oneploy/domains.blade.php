<div>
    <x-dashboard.navbar section="domains" title="Domains" subtitle="Search and register through OnePloy. Purchase is blocked until ConnectReseller credentials are configured." />
    <form wire:submit="search" class="mb-6 flex max-w-xl gap-2">
        <input wire:model="query" class="input w-full" placeholder="example.com" />
        <x-forms.button type="submit">Search</x-forms.button>
    </form>
    @if ($result)
        <div class="mb-8 rounded-xl border border-neutral-200 p-4 text-sm dark:border-white/10">
            <pre class="whitespace-pre-wrap">{{ json_encode($result, JSON_PRETTY_PRINT) }}</pre>
        </div>
    @endif
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">Your domains</h2>
    <ul class="space-y-2">
        @forelse ($domains as $domain)
            <li class="rounded-lg border border-neutral-200 px-3 py-2 text-sm dark:border-white/10">
                {{ $domain->name }} · {{ $domain->status }}
            </li>
        @empty
            <li class="text-sm text-neutral-500">No domains on this team yet.</li>
        @endforelse
    </ul>
</div>
