<x-layouts::app :title="__('Feature flags & experiments')">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">Feature flags & experiments</h1>
            <p class="mt-1 text-sm text-zinc-500">Read-only status. Configuration changes are restricted to audited operational commands.</p>
        </div>

        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <h2 class="mb-3 font-semibold">Feature flags</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-zinc-500"><tr><th class="p-2">Key</th><th class="p-2">Scope</th><th class="p-2">Status</th><th class="p-2">Rollout</th><th class="p-2">Window</th><th class="p-2">Version</th></tr></thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse ($flags as $flag)
                            <tr><td class="p-2 font-mono">{{ $flag->key }}</td><td class="p-2">{{ $flag->scope }}</td><td class="p-2">{{ $flag->kill_switch ? 'Killed' : ($flag->isActive() ? 'Active' : 'Inactive') }}</td><td class="p-2">{{ number_format($flag->rollout_basis_points / 100, 2) }}%</td><td class="p-2 text-xs">{{ $flag->starts_at?->format('M j H:i') ?? 'Always' }} → {{ $flag->ends_at?->format('M j H:i') ?? 'No end' }}</td><td class="p-2">v{{ $flag->version }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="p-4 text-center text-zinc-500">No feature flags configured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <h2 class="mb-3 font-semibold">Experiments</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-zinc-500"><tr><th class="p-2">Key</th><th class="p-2">Status</th><th class="p-2">Allocation</th><th class="p-2">Variants</th><th class="p-2">Assigned</th><th class="p-2">Version</th></tr></thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse ($experiments as $experiment)
                            <tr>
                                <td class="p-2 font-mono">{{ $experiment->key }}</td>
                                <td class="p-2">{{ $experiment->kill_switch ? 'Killed' : ($experiment->isActive() ? 'Active' : 'Inactive') }}</td>
                                <td class="p-2">{{ number_format($experiment->allocation_basis_points / 100, 2) }}%</td>
                                <td class="p-2 text-xs">
                                    @foreach ($experiment->variants as $variant => $weight)
                                        <span class="mr-2">{{ $variant }} {{ number_format($weight / 100, 2) }}% ({{ (int) optional($variantCounts->get($experiment->id)?->firstWhere('variant', $variant))->aggregate }})</span>
                                    @endforeach
                                </td>
                                <td class="p-2">{{ $experiment->assignments_count }}</td><td class="p-2">v{{ $experiment->version }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-4 text-center text-zinc-500">No experiments configured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts::app>
