<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
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
