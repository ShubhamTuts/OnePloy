<div>
    <x-dashboard.navbar section="usage" title="Usage" subtitle="Included limits from the tenant plan. Overage billing requires payment provider keys." />
    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-white/10">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-left dark:bg-white/5">
                <tr>
                    <th class="px-4 py-2">Meter</th>
                    <th class="px-4 py-2">Used</th>
                    <th class="px-4 py-2">Included</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($quotas as $key => $row)
                    <tr class="border-t border-neutral-200 dark:border-white/10">
                        <td class="px-4 py-2">{{ str($key)->beforeLast('.')->headline() }}</td>
                        <td class="px-4 py-2">{{ $row['used'] }}</td>
                        <td class="px-4 py-2">{{ $row['limit'] ?? 'Unlimited' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
