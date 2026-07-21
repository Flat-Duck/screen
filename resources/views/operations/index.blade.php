<x-layouts::app :title="__('Operations')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">Operations health</h1>
                <p class="text-sm text-zinc-500">Bounded snapshots; no secrets or raw exception messages are displayed.</p>
            </div>
            @if ($snapshot)
                <span class="rounded-full px-3 py-1 text-xs font-medium {{ $snapshot->status === 'healthy' && ! $snapshotStale ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' }}">
                    {{ $snapshotStale ? 'Stale' : ucfirst($snapshot->status) }} · {{ $snapshot->captured_at->diffForHumans() }}
                </span>
            @endif
        </div>

        @if (! $snapshot)
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                No snapshot exists. Run <code>php artisan operations:capture-health</code> and ensure the scheduler runs every minute.
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ($snapshot->checks as $name => $check)
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-sm capitalize text-zinc-500">{{ $name }}</div>
                        <div class="mt-1 text-xl font-semibold {{ $check['status'] === 'failed' ? 'text-red-600' : ($check['status'] === 'not_configured' ? 'text-amber-600' : 'text-emerald-600') }}">{{ str_replace('_', ' ', ucfirst($check['status'])) }}</div>
                    </div>
                @endforeach
            </div>

            @php($metrics = $snapshot->metrics)
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['Security outbox', data_get($metrics, 'security_outbox_backlog', 0)],
                    ['Media failures', data_get($metrics, 'media_processing_failures', 0)],
                    ['Cleanup failures', data_get($metrics, 'cleanup_failures', 0)],
                    ['Stored screenshot bytes', number_format(data_get($metrics, 'storage_bytes', 0))],
                ] as [$label, $value])
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-sm text-zinc-500">{{ $label }}</div>
                        <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <h2 class="font-semibold">Queue backlog by queue</h2>
                    <div class="mt-3 space-y-2 text-sm">
                        @forelse (data_get($metrics, 'queue_backlog', []) as $queue => $count)
                            <div class="flex justify-between"><span>{{ $queue }}</span><span class="font-medium">{{ $count }}</span></div>
                        @empty <p class="text-zinc-500">No queued jobs.</p> @endforelse
                    </div>
                    <h3 class="mt-5 text-sm font-semibold">Failed in the last 24 hours</h3>
                    <div class="mt-2 space-y-2 text-sm">
                        @forelse (data_get($metrics, 'failed_jobs_24h', []) as $queue => $count)
                            <div class="flex justify-between text-red-600"><span>{{ $queue }}</span><span class="font-medium">{{ $count }}</span></div>
                        @empty <p class="text-zinc-500">No failed jobs.</p> @endforelse
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <h2 class="font-semibold">30-day app-version adoption</h2>
                    <div class="mt-3 space-y-2 text-sm">
                        @forelse (data_get($metrics, 'app_versions', []) as $version)
                            <div class="flex justify-between"><span>{{ $version['version'] }}</span><span class="font-medium">{{ number_format($version['devices']) }} devices</span></div>
                        @empty <p class="text-zinc-500">No recently active version data.</p> @endforelse
                    </div>
                    <p class="mt-4 text-xs text-zinc-500">24-hour storage growth: {{ $storageGrowth === null ? 'awaiting baseline' : number_format($storageGrowth).' bytes' }}</p>
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <h2 class="font-semibold">API traffic · last 60 minutes</h2>
            @if ($apiMetrics->isEmpty())
                <p class="mt-3 text-sm text-zinc-500">No API requests recorded yet.</p>
            @else
                @php($maxRequests = max(1, (int) $apiMetrics->max('requests')))
                <div class="mt-4 flex h-28 items-end gap-1" aria-label="API request volume chart">
                    @foreach ($apiMetrics as $metric)
                        <div class="min-w-1 flex-1 rounded-t bg-blue-500" style="height: {{ max(3, ($metric->requests / $maxRequests) * 100) }}%" title="{{ $metric->minute }}: {{ $metric->requests }} requests, {{ $metric->average_duration_ms }}ms average"></div>
                    @endforeach
                </div>
                <div class="mt-3 grid gap-2 text-sm sm:grid-cols-4">
                    <span>Requests: {{ number_format($apiMetrics->sum('requests')) }}</span>
                    <span>Errors: {{ number_format($apiMetrics->sum('errors')) }}</span>
                    <span>Rate limited: {{ number_format($apiMetrics->sum('rate_limited')) }}</span>
                    <span>Peak latency: {{ number_format($apiMetrics->max('max_duration_ms')) }}ms</span>
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <h2 class="font-semibold">Scheduled workflows</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm"><thead class="text-zinc-500"><tr><th class="py-2">Task</th><th>Status</th><th>Last success</th><th>Runtime</th></tr></thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($tasks as $task)
                        <tr><td class="py-2 pr-4 font-mono text-xs">{{ $task->task_name }}</td><td>{{ ucfirst($task->status) }}</td><td>{{ $task->last_succeeded_at?->diffForHumans() ?? 'Never' }}</td><td>{{ $task->runtime_ms === null ? '—' : $task->runtime_ms.'ms' }}</td></tr>
                    @empty <tr><td colspan="4" class="py-4 text-zinc-500">No scheduled task has run since monitoring was deployed.</td></tr> @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts::app>
