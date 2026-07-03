<x-layouts::app :title="$event->name">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-2">
                    @if ($event->kind === 'fatal_crash')
                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-300">fatal</span>
                    @elseif ($event->kind === 'error')
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">error</span>
                    @else
                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">event</span>
                    @endif
                    <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">{{ $event->name }}</h1>
                </div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Occurred {{ $event->occurred_at->diffForHumans() }} · Received {{ $event->received_at->diffForHumans() }}
                </p>
            </div>
            <a href="{{ route('events.index') }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">← All events</a>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-xs uppercase text-zinc-500 dark:text-zinc-400">Device</div>
            <a href="{{ route('devices.show', $event->device) }}" class="mt-1 block text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                {{ $event->device->manufacturer }} {{ $event->device->model }} — {{ $event->device->device_uuid }}
            </a>
        </div>

        @if (! empty($event->extras))
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <h2 class="mb-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">Extras</h2>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    @foreach ($event->extras as $key => $value)
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $key }}</dt>
                        <dd class="break-all">{{ $value }}</dd>
                    @endforeach
                </dl>
            </div>
        @endif

        @if ($event->kind !== 'event')
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <h2 class="mb-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">Error details</h2>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-zinc-500 dark:text-zinc-400">Tag</dt>
                    <dd>{{ $event->error_tag }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">Exception class</dt>
                    <dd>{{ $event->exception_class }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">Message</dt>
                    <dd>{{ $event->error_message ?? '—' }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">Thread</dt>
                    <dd>{{ $event->thread_name }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">Fatal?</dt>
                    <dd>{{ $event->is_fatal ? 'Yes' : 'No' }}</dd>
                </dl>

                @if ($event->stack_trace)
                    <h3 class="mb-1 mt-4 text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">Stack trace</h3>
                    <pre class="overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">{{ $event->stack_trace }}</pre>
                @endif
            </div>
        @endif

        @if (! empty($event->breadcrumbs))
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <h2 class="mb-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">Breadcrumb trail (what led up to this)</h2>
                <ol class="space-y-2">
                    @foreach ($event->breadcrumbs as $crumb)
                        <li class="flex items-start gap-3 border-l-2 border-zinc-200 py-1 pl-3 text-sm dark:border-zinc-700">
                            <span class="whitespace-nowrap text-xs text-zinc-500 dark:text-zinc-400">{{ $crumb['ts'] ?? '' }}</span>
                            <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs dark:bg-zinc-800">{{ $crumb['type'] ?? '' }}</span>
                            <span>{{ $crumb['name'] ?? '' }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </div>
</x-layouts::app>
