<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold">Screenshot Analytics</h1>
        <p class="mt-2 text-sm text-zinc-500">From tracking rollout onward. Dates are UTC capture cohorts; late completions update the original capture. Historical user attribution is from the detection session.</p>
    </div>
    <div class="grid gap-4 sm:grid-cols-4">
        <flux:input type="date" label="From" wire:model.live="from" />
        <flux:input type="date" label="To" wire:model.live="to" />
        <flux:input label="User ID or anonymous" placeholder="All users" wire:model.live.debounce.400ms="user" />
        <flux:input label="Device ID" placeholder="All devices" wire:model.live.debounce.400ms="device" />
    </div>
    <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-5">
        @foreach (\App\Services\Screenshots\CaptureReport::LABELS as $key => $label)
            <div wire:key="metric-{{ $key }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <p class="text-sm text-zinc-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-semibold">{{ number_format($totals->$key) }}</p>
                <p class="text-xs text-zinc-500">Lifetime: {{ number_format($lifetime->$key) }}</p>
            </div>
        @endforeach
    </div>
    <div class="flex flex-wrap gap-6 text-sm">
        <span>Ignored / displayed: {{ $totals->overlay_shown ? number_format(100 * $totals->ignored / $totals->overlay_shown, 1).'%' : '—' }}</span>
        <span>Published / Share taps: {{ $totals->share_tapped ? number_format(100 * $totals->share_converted / $totals->share_tapped, 1).'%' : '—' }}</span>
        <span>Saved / Save taps: {{ $totals->private_save_tapped ? number_format(100 * $totals->private_save_converted / $totals->private_save_tapped, 1).'%' : '—' }}</span>
    </div>
    <p class="text-sm text-zinc-500">Counts are unique captures per stage, not button presses. A retried flow can have both a failure and a completion. “Completion not recorded” does not mean canceled. Missing detection events remain unattributed until received.</p>
    <h2 class="text-lg font-semibold">Daily trends</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr><th class="p-3">Capture day</th><th class="p-3">Detected</th><th class="p-3">Ignored</th><th class="p-3">Share taps / published</th><th class="p-3">Save taps / saved</th></tr></thead>
            <tbody>
            @forelse ($daily as $day)
                <tr wire:key="day-{{ $day->day }}" class="border-t border-zinc-200 dark:border-zinc-700"><td class="p-3">{{ $day->day }}</td><td class="p-3">{{ $day->detected }}</td><td class="p-3">{{ $day->ignored }}</td><td class="p-3">{{ $day->share_tapped }} / {{ $day->share_completed }}</td><td class="p-3">{{ $day->private_save_tapped }} / {{ $day->private_save_completed }}</td></tr>
            @empty
                <tr><td colspan="5" class="p-4">No screenshot activity in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <h2 class="text-lg font-semibold">Users and devices</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr><th class="p-3">User</th><th class="p-3">Device</th><th class="p-3">Detected / ignored</th><th class="p-3">Share taps / published</th><th class="p-3">Save taps / saved</th><th class="p-3">Unfinished</th></tr></thead>
            <tbody>
            @foreach ($breakdown as $row)
                <tr wire:key="capture-{{ $row->device_id }}-{{ $row->user_id ?? 'anonymous' }}" class="border-t border-zinc-200 dark:border-zinc-700"><td class="p-3">{{ $row->username ?? 'Anonymous / unavailable' }} {{ $row->user_id }}</td><td class="p-3">#{{ $row->device_id }} {{ $row->model }}</td><td class="p-3">{{ $row->detected }} / {{ $row->ignored }}</td><td class="p-3">{{ $row->share_tapped }} / {{ $row->share_completed }}</td><td class="p-3">{{ $row->private_save_tapped }} / {{ $row->private_save_completed }}</td><td class="p-3">{{ $row->unfinished }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $breakdown->links() }}
    <h2 class="text-lg font-semibold">Latest phone library counts</h2>
    <p class="text-sm text-zinc-500">Accessible screenshot files, including files from before installation. Device counts are not unique images across devices. Independent of the date filter; snapshots older than 24 hours are marked stale.</p>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr><th class="p-3">Device</th><th class="p-3">Observed session user</th><th class="p-3">Files</th><th class="p-3">Coverage</th><th class="p-3">Last observed (UTC)</th></tr></thead>
            <tbody>
            @forelse ($libraries as $snapshot)
                <tr wire:key="library-{{ $snapshot->device_id }}" class="border-t border-zinc-200 dark:border-zinc-700"><td class="p-3">#{{ $snapshot->device_id }} {{ $snapshot->model }}</td><td class="p-3">{{ $snapshot->username ?? 'Anonymous / unavailable' }}</td><td class="p-3">{{ $snapshot->screenshot_count ?? 'Unavailable' }}</td><td class="p-3">{{ $snapshot->coverage }}</td><td class="p-3">{{ $snapshot->observed_at }} {{ \Carbon\CarbonImmutable::parse($snapshot->observed_at)->lt(now()->subDay()) ? '(stale)' : '' }}</td></tr>
            @empty
                <tr><td colspan="5" class="p-4">No library snapshots received yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $libraries->links() }}
</div>
