<div>
    <x-dashboard.navbar section="marketplace" title="Marketplace" subtitle="Certified and community templates. Deploy through the existing service catalogue — this is metadata, not a second runtime." />
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($apps as $app)
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-white/10">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-semibold">{{ $app->name }}</h2>
                    <span class="text-xs uppercase tracking-wide text-neutral-500">{{ $app->certification }}</span>
                </div>
                <p class="mt-1 text-sm text-neutral-500">{{ $app->category }} · {{ str_replace('_', ' ', $app->product_level) }}</p>
                <p class="mt-3 text-sm">Template: <code>{{ $app->template_file }}</code></p>
                <a class="mt-4 inline-flex text-sm font-medium underline" href="{{ route('project.index') }}">Deploy from Projects</a>
            </div>
        @empty
            <p class="text-sm text-neutral-500">Catalog will appear after <code>php artisan oneploy:bootstrap</code>.</p>
        @endforelse
    </div>
</div>
