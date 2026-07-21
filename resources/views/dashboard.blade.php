<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">Product overview</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">UTC metric boundaries; dates are localized only for display.</p>
            </div>
            @if ($metricDate)
                <span class="rounded-full px-3 py-1 text-xs font-medium {{ $isPartial ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' }}">
                    {{ $metricDate->format('M j, Y') }} · {{ $isPartial ? 'Partial day' : 'Complete day' }}
                </span>
            @else
                <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Awaiting first aggregate</span>
            @endif
        </div>

        <div class="grid auto-rows-min gap-4 sm:grid-cols-2 xl:grid-cols-5">
            @foreach ([
                ['DAU', $dau], ['WAU', $wau], ['Registrations', $registrations],
                ['Active creators', $activeCreators], ['Screenshots published', $screenshotsPublished],
            ] as [$label, $value])
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                    <div class="mt-1 text-3xl font-semibold">{{ number_format($value) }}</div>
                </div>
            @endforeach
        </div>

        <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Open / impression <span class="text-xs">(7d)</span></div>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($openRate, 2) }}%</div>
                <div class="mt-2 text-xs text-zinc-500">Save {{ number_format($saveRate, 2) }}% · Follow {{ number_format($followRate, 2) }}%</div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Negative feedback <span class="text-xs">(7d)</span></div>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($hideRate, 2) }}% hide</div>
                <div class="mt-2 text-xs text-zinc-500">{{ number_format($reportRate, 2) }}% report / impression</div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Day-1 retention</div>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($dayOneRetention, 2) }}%</div>
                <div class="mt-2 text-xs text-zinc-500">Latest available signup cohort</div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Crash-free sessions</div>
                <div class="mt-1 text-2xl font-semibold {{ $crashFreeSessions < 99 ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ number_format($crashFreeSessions, 2) }}%</div>
                <div class="mt-2 text-xs text-zinc-500">Sessions started on metric day</div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 lg:col-span-2">
                <h2 class="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-200">14-day activity trend</h2>
                @if ($trend->isEmpty())
                    <p class="text-sm text-zinc-500">No aggregate data yet. Run <code>php artisan analytics:aggregate --date=today</code>.</p>
                @else
                    @php($maxDau = max(1, (int) $trend->max('daily_active_users')))
                    <div class="space-y-2">
                        @foreach ($trend as $day)
                            <div class="grid grid-cols-[5rem_1fr_3rem] items-center gap-2 text-xs">
                                <span class="text-zinc-500">{{ $day->metric_date->format('M j') }}{{ $day->is_partial ? '*' : '' }}</span>
                                <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <div class="h-full rounded-full bg-blue-500" style="width: {{ max(2, ($day->daily_active_users / $maxDau) * 100) }}%"></div>
                                </div>
                                <span class="text-right font-medium">{{ $day->daily_active_users }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-zinc-500">* current UTC day is partial</p>
                @endif
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Moderation health</h2>
                <div class="mt-4 text-3xl font-semibold {{ $moderationBacklog > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ $moderationBacklog }}</div>
                <div class="text-sm text-zinc-500">open or investigating cases</div>
                <div class="mt-4 text-sm text-zinc-600 dark:text-zinc-300">Oldest case: {{ $oldestModerationAgeHours }}h</div>
                <a href="{{ route('moderation.cases.index') }}" class="mt-3 inline-block text-sm text-blue-600 hover:underline dark:text-blue-400">Open moderation queue →</a>
            </div>
        </div>

        <h2 class="mt-2 text-sm font-semibold uppercase tracking-wide text-zinc-500">Telemetry operations</h2>
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Total devices</div>
                <div class="mt-1 text-3xl font-semibold">{{ $totalDevices }}</div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Events today</div>
                <div class="mt-1 text-3xl font-semibold">{{ $eventsToday }}</div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Crashes today</div>
                <div class="mt-1 text-3xl font-semibold {{ $crashesToday > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                    {{ $crashesToday }}
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Recent crashes</h2>
                <a href="{{ route('events.index') }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">View all events →</a>
            </div>

            @if ($recentCrashes->isEmpty())
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No crashes reported yet.</p>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @foreach ($recentCrashes as $crash)
                        <li class="flex items-center justify-between py-2 text-sm">
                            <div>
                                <a href="{{ route('events.show', $crash) }}" class="font-medium text-blue-600 hover:underline dark:text-blue-400">
                                    {{ $crash->exception_class ?? $crash->name }}
                                </a>
                                <span class="text-zinc-500 dark:text-zinc-400">
                                    on {{ $crash->device->manufacturer }} {{ $crash->device->model }}
                                </span>
                            </div>
                            <span class="whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $crash->received_at->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-layouts::app>
