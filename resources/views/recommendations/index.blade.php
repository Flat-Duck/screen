<x-layouts::app :title="__('Recommendations')">
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">Recommendation administration</flux:heading>
                <flux:text>Inspect hot pools, recent score snapshots, feedback risk, and global exclusions.</flux:text>
            </div>
            <flux:badge :color="$servingEnabled ? 'green' : 'red'">For You {{ $servingEnabled ? 'enabled' : 'disabled' }}</flux:badge>
        </div>

        @can('manageModeration')
            <form method="POST" action="{{ route('recommendations.serving') }}" class="flex items-end gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                @csrf
                <input type="hidden" name="enabled" value="{{ $servingEnabled ? 0 : 1 }}">
                <flux:input name="reason" label="Audit reason" required class="max-w-xl" />
                <flux:button type="submit" :variant="$servingEnabled ? 'danger' : 'primary'">{{ $servingEnabled ? 'Disable For You' : 'Enable For You' }}</flux:button>
            </form>
        @endcan

        <section>
            <flux:heading size="lg">Interest onboarding</flux:heading>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><div class="text-sm text-zinc-500">Completed</div><div class="text-2xl font-semibold">{{ number_format($interestOnboarding['completed']) }}</div></div>
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><div class="text-sm text-zinc-500">Skipped</div><div class="text-2xl font-semibold">{{ number_format($interestOnboarding['skipped']) }}</div></div>
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><div class="text-sm text-zinc-500">Still pending</div><div class="text-2xl font-semibold">{{ number_format($interestOnboarding['pending']) }}</div></div>
            </div>
            <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm"><thead><tr class="text-left"><th class="p-3">Interest</th><th>Slug</th><th>Selections</th><th>Status</th></tr></thead><tbody>
                    @foreach($popularInterests as $interest)
                        <tr class="border-t border-zinc-200 dark:border-zinc-700"><td class="p-3">{{ $interest->name }}</td><td>{{ $interest->slug }}</td><td>{{ number_format($interest->users_count) }}</td><td>{{ $interest->is_active ? 'Active' : 'Inactive' }}</td></tr>
                    @endforeach
                </tbody></table>
            </div>
        </section>

        <section>
            <flux:heading size="lg">Current global hot pool</flux:heading>
            <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm"><thead><tr class="text-left"><th class="p-3">Post</th><th>Author</th><th>Created</th><th>Action</th></tr></thead><tbody>
                @forelse($hotPosts as $post)
                    <tr class="border-t border-zinc-200 dark:border-zinc-700"><td class="p-3">#{{ $post->id }}</td><td>{{ '@'.$post->user->username }}</td><td>{{ $post->created_at }}</td><td class="py-2">
                        @can('manageModeration')
                            <form method="POST" action="{{ route('recommendations.exclude', $post) }}" class="flex gap-2">@csrf
                                <input name="reason" required minlength="3" placeholder="Reason" class="rounded border px-2 dark:bg-zinc-900">
                                <input name="expires_at" type="datetime-local" class="rounded border px-2 dark:bg-zinc-900">
                                <flux:button size="sm" type="submit">Exclude</flux:button>
                            </form>
                        @endcan
                    </td></tr>
                @empty
                    <tr><td colspan="4" class="p-4 text-zinc-500">No Redis hot-pool entries are currently available.</td></tr>
                @endforelse
                </tbody></table>
            </div>
        </section>

        <section>
            <flux:heading size="lg">Active and recent exclusions</flux:heading>
            <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm"><thead><tr class="text-left"><th class="p-3">Post</th><th>Reason</th><th>Expires</th><th>Action</th></tr></thead><tbody>
                @forelse($exclusions as $exclusion)
                    <tr class="border-t border-zinc-200 dark:border-zinc-700"><td class="p-3">#{{ $exclusion->post_id }}</td><td>{{ $exclusion->reason }}</td><td>{{ $exclusion->expires_at ?? 'Never' }}</td><td>
                        @can('manageModeration')
                            <form method="POST" action="{{ route('recommendations.restore', $exclusion) }}" class="flex gap-2">@csrf @method('DELETE')
                                <input name="reason" required minlength="3" placeholder="Restore reason" class="rounded border px-2 dark:bg-zinc-900">
                                <flux:button size="sm" type="submit">Restore</flux:button>
                            </form>
                        @endcan
                    </td></tr>
                @empty
                    <tr><td colspan="4" class="p-4 text-zinc-500">No exclusions.</td></tr>
                @endforelse
                </tbody></table>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section>
                <flux:heading size="lg">Recent score snapshots</flux:heading>
                <div class="mt-3 space-y-2">
                    @forelse($sessions as $session)
                        <details class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700"><summary class="cursor-pointer">{{ $session->request_id }} · {{ count($session->items) }} candidates · {{ $session->ranking_version }}</summary>
                            <pre class="mt-2 max-h-64 overflow-auto text-xs">{{ json_encode(array_slice($session->items, 0, 10), JSON_PRETTY_PRINT) }}</pre>
                        </details>
                    @empty <flux:text>No recent feed sessions.</flux:text> @endforelse
                </div>
            </section>
            <section>
                <flux:heading size="lg">Anomaly indicators</flux:heading>
                <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm"><thead><tr class="text-left"><th class="p-3">Post</th><th>Impressions</th><th>Reports</th><th>Negative</th></tr></thead><tbody>
                    @forelse($anomalies as $row)
                        <tr class="border-t border-zinc-200 dark:border-zinc-700"><td class="p-3">#{{ $row->post_id }}</td><td>{{ $row->impressions }}</td><td>{{ $row->reports }}</td><td>{{ $row->negative_feedback }}</td></tr>
                    @empty <tr><td colspan="4" class="p-4 text-zinc-500">No aggregate signals.</td></tr> @endforelse
                    </tbody></table>
                </div>
            </section>
        </div>
    </div>
</x-layouts::app>
