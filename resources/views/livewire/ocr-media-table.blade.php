<div class="flex flex-col gap-4">
    @if($flashMessage)
        <div class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">{{ $flashMessage }}</div>
    @endif

    <div class="grid gap-3 md:grid-cols-5">
        <input wire:model.live.debounce.300ms="search" placeholder="Media id, post id, or author" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900" />
        <select wire:model.live="status" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">All statuses</option><option value="ready">Ready</option><option value="failed">Failed</option><option value="processing">Processing</option><option value="pending">Pending</option><option value="skipped">Never ran</option>
        </select>
        <select wire:model.live="source" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">Any source</option><option value="server">Server</option><option value="device">Device</option><option value="unknown">Unknown</option>
        </select>
        <select wire:model.live="safety" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">Any safety</option><option value="clear">Clear</option><option value="warning">Sensitive</option><option value="failed">Failed</option>
        </select>
        <select wire:model.live="text" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">Any result</option><option value="present">Has text</option><option value="empty">No text</option>
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase dark:bg-zinc-900">
                <tr>
                    <th class="p-3">Media</th><th class="p-3">Author</th><th class="p-3">Status</th><th class="p-3">Source</th>
                    <th class="p-3">Lang</th><th class="p-3">Chars</th><th class="p-3">Time</th><th class="p-3">Safety</th><th class="p-3">Text</th>
                </tr>
            </thead>
            <tbody class="divide-y dark:divide-zinc-800">
                @forelse($media as $item)
                    @php($verification = $verifications[$item->id] ?? null)
                    <tr>
                        <td class="p-3">
                            <a class="font-medium text-blue-600" href="{{ route('moderation.content.show', $item->post_id) }}">#{{ $item->id }}</a>
                            <div class="text-xs text-zinc-500">post {{ $item->post_id }}</div>
                        </td>
                        <td class="p-3 text-zinc-600 dark:text-zinc-400">{{ $item->post?->user?->username ?? '—' }}</td>
                        <td class="p-3">
                            <span @class([
                                'rounded px-2 py-0.5 text-xs font-medium',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200' => $item->ocr_status === 'ready',
                                'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' => $item->ocr_status === 'failed',
                                'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! in_array($item->ocr_status, ['ready', 'failed'], true),
                            ])>{{ $item->ocr_status === 'skipped' ? 'never ran' : $item->ocr_status }}</span>
                        </td>
                        <td class="p-3">
                            {{ $item->ocr_source ?? 'unknown' }}
                            @if($verification)
                                <div @class([
                                    'text-xs',
                                    'text-red-600' => $verification->verdict === 'mismatch',
                                    'text-zinc-500' => $verification->verdict !== 'mismatch',
                                ])>
                                    {{ $verification->verdict }}@if($verification->similarity !== null) · {{ round($verification->similarity * 100) }}% similar @endif
                                </div>
                            @endif
                        </td>
                        <td class="p-3 text-zinc-600 dark:text-zinc-400">{{ $item->ocr_language ?? '—' }}</td>
                        <td class="p-3 text-zinc-600 dark:text-zinc-400">{{ $item->ocr_text === null ? '—' : mb_strlen($item->ocr_text) }}</td>
                        <td class="p-3 text-zinc-600 dark:text-zinc-400">{{ $item->ocr_duration_ms === null ? '—' : $item->ocr_duration_ms.' ms' }}</td>
                        <td class="p-3">
                            @if($item->safety_status === 'warning')
                                <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/50 dark:text-amber-200">sensitive</span>
                            @else
                                <span class="text-zinc-500">{{ $item->safety_status }}</span>
                            @endif
                        </td>
                        <td class="p-3">
                            @if($item->ocr_text === null)
                                <span class="text-xs text-zinc-500">no text</span>
                            @elseif($revealedId === $item->id)
                                <div class="max-w-md">
                                    <pre class="max-h-48 overflow-auto whitespace-pre-wrap rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-900">{{ $item->ocr_text }}</pre>
                                    <button wire:click="hide" class="mt-1 text-xs text-blue-600">Hide</button>
                                </div>
                            @elseif($revealingId === $item->id)
                                <div class="flex w-64 flex-col gap-1">
                                    <input wire:model="revealReason" placeholder="Why do you need to read this?" class="rounded border px-2 py-1 text-xs dark:bg-zinc-900" />
                                    @error('revealReason')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                                    <div class="flex gap-2">
                                        <button wire:click="reveal" class="rounded bg-zinc-800 px-2 py-1 text-xs text-white">Reveal</button>
                                        <button wire:click="cancelReveal" class="rounded border px-2 py-1 text-xs">Cancel</button>
                                    </div>
                                </div>
                            @else
                                <span class="text-xs text-zinc-500">redacted</span>
                                @can('manageModeration')
                                    <button wire:click="startReveal({{ $item->id }})" class="ml-2 text-xs text-blue-600">Reveal</button>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="p-8 text-center text-zinc-500">No media matches.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $media->links() }}
</div>
