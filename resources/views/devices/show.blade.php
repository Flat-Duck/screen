<x-layouts::app :title="$device->manufacturer.' '.$device->model">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">
                    {{ $device->manufacturer }} {{ $device->model }}
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $device->device_uuid }}</p>
            </div>
            <a href="{{ route('devices.index') }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">← All devices</a>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-xs uppercase text-zinc-500 dark:text-zinc-400">OS</div>
                <div class="mt-1 text-sm">{{ $device->os_name }} {{ $device->os_version }} (SDK {{ $device->sdk_int }})</div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-xs uppercase text-zinc-500 dark:text-zinc-400">App version</div>
                <div class="mt-1 text-sm">{{ $device->app_version_name }} ({{ $device->app_version_code }})</div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-xs uppercase text-zinc-500 dark:text-zinc-400">First seen</div>
                <div class="mt-1 text-sm">{{ $device->first_seen_at?->diffForHumans() ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-xs uppercase text-zinc-500 dark:text-zinc-400">Last seen</div>
                <div class="mt-1 text-sm">{{ $device->last_seen_at?->diffForHumans() ?? '—' }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 p-4 dark:border-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Recent activity (last 50)</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3">Kind</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Occurred</th>
                            <th class="px-4 py-3">Received</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse ($device->telemetryEvents as $event)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900">
                                <td class="px-4 py-3">
                                    @if ($event->kind === 'fatal_crash')
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-300">fatal</span>
                                    @elseif ($event->kind === 'error')
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">error</span>
                                    @else
                                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">event</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('events.show', $event) }}" class="font-medium text-blue-600 hover:underline dark:text-blue-400">
                                        {{ $event->name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $event->occurred_at->diffForHumans() }}</td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $event->received_at->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No activity yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts::app>
