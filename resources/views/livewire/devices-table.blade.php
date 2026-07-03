<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between gap-4">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search by UUID, manufacturer, brand, model…"
            class="w-full max-w-sm rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
        />
        <span class="whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-400">
            {{ $devices->total() }} device{{ $devices->total() === 1 ? '' : 's' }}
        </span>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="cursor-pointer px-4 py-3" wire:click="sortBy('model')">Device</th>
                    <th class="px-4 py-3">OS</th>
                    <th class="px-4 py-3">App Version</th>
                    <th class="cursor-pointer px-4 py-3" wire:click="sortBy('events_count')">Events</th>
                    <th class="cursor-pointer px-4 py-3" wire:click="sortBy('crashes_count')">Crashes</th>
                    <th class="cursor-pointer px-4 py-3" wire:click="sortBy('last_seen_at')">Last Seen</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($devices as $device)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900">
                        <td class="px-4 py-3">
                            <a href="{{ route('devices.show', $device) }}" class="font-medium text-blue-600 hover:underline dark:text-blue-400">
                                {{ $device->manufacturer }} {{ $device->model }}
                            </a>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $device->device_uuid }}</div>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $device->os_name }} {{ $device->os_version }} (SDK {{ $device->sdk_int }})</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $device->app_version_name }} ({{ $device->app_version_code }})</td>
                        <td class="px-4 py-3">{{ $device->events_count }}</td>
                        <td class="px-4 py-3">
                            @if ($device->crashes_count > 0)
                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-300">
                                    {{ $device->crashes_count }}
                                </span>
                            @else
                                <span class="text-zinc-400">0</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            {{ $device->last_seen_at?->diffForHumans() ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No devices yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $devices->links() }}
</div>
