<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by name, exception, or device…"
                class="w-full max-w-sm rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            />
            <select
                wire:model.live="kind"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            >
                <option value="">All kinds</option>
                <option value="event">Events</option>
                <option value="error">Non-fatal errors</option>
                <option value="fatal_crash">Fatal crashes</option>
            </select>
        </div>
        <span class="whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-400">
            {{ $events->total() }} result{{ $events->total() === 1 ? '' : 's' }}
        </span>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3">Kind</th>
                    <th class="cursor-pointer px-4 py-3" wire:click="sortBy('name')">Name</th>
                    <th class="px-4 py-3">Device</th>
                    <th class="px-4 py-3">User / Release</th>
                    <th class="cursor-pointer px-4 py-3" wire:click="sortBy('occurred_at')">Occurred</th>
                    <th class="cursor-pointer px-4 py-3" wire:click="sortBy('received_at')">Received</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($events as $event)
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
                            @if ($event->exception_class)
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $event->exception_class }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('devices.show', $event->device) }}" class="text-zinc-600 hover:underline dark:text-zinc-300">
                                {{ $event->device->manufacturer }} {{ $event->device->model }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            <div>{{ $event->user?->email ?? 'Anonymous' }}</div>
                            <div class="text-xs text-zinc-500">{{ $event->app_version_name ?? '—' }} ({{ $event->app_version_code ?? '—' }})</div>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $event->occurred_at->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $event->received_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No events yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $events->links() }}
</div>
