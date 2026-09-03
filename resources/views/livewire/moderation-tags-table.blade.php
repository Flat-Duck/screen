<div class="flex flex-col gap-4">
    @if($flashMessage)
        <div class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">{{ $flashMessage }}</div>
    @endif

    <div class="grid gap-3 md:grid-cols-3">
        <input wire:model.live.debounce.300ms="search" placeholder="Tag name" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900" />
        <select wire:model.live="state" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">All states</option>
            @foreach($states as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
        </select>
        <select wire:model.live="windowDays" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="1">Last 24 hours</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase dark:bg-zinc-900">
                <tr>
                    <th class="p-3">Tag</th>
                    <th class="p-3">Recent posts</th>
                    <th class="p-3">All-time</th>
                    <th class="p-3">Reports</th>
                    <th class="p-3">State</th>
                    <th class="p-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y dark:divide-zinc-800">
                @forelse($hashtags as $hashtag)
                    <tr>
                        <td class="p-3 font-medium text-zinc-800 dark:text-zinc-100">#{{ $hashtag->name }}</td>
                        <td class="p-3">{{ $hashtag->recent_posts }}</td>
                        <td class="p-3 text-zinc-600 dark:text-zinc-400">{{ $hashtag->posts_count }}</td>
                        <td class="p-3 @if(($reportCounts[$hashtag->id] ?? 0) > 0) font-medium text-red-600 @endif">{{ $reportCounts[$hashtag->id] ?? 0 }}</td>
                        <td class="p-3">
                            <span @class([
                                'rounded px-2 py-0.5 text-xs font-medium',
                                'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' => $hashtag->moderation_state->value === 'clear',
                                'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200' => $hashtag->moderation_state->value === 'not_recommended',
                                'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' => $hashtag->moderation_state->value === 'blocked',
                            ])>{{ $hashtag->moderation_state->label() }}</span>
                            @if($hashtag->moderation_reason)
                                <div class="mt-1 max-w-xs truncate text-xs text-zinc-500" title="{{ $hashtag->moderation_reason }}">{{ $hashtag->moderation_reason }}</div>
                            @endif
                            @if($hashtag->moderator)<div class="text-xs text-zinc-500">by {{ $hashtag->moderator->username }}</div>@endif
                        </td>
                        <td class="p-3">
                            @can('manageModeration')
                                @if($actingOnId === $hashtag->id)
                                    <div class="flex flex-col gap-2">
                                        <div class="text-xs text-zinc-500">Setting to <strong>{{ $targetState }}</strong></div>
                                        <input wire:model="reason" placeholder="Reason (required)" class="rounded border px-2 py-1 text-xs dark:bg-zinc-900" />
                                        @error('reason')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                                        <div class="flex gap-2">
                                            <button wire:click="applyState" class="rounded bg-emerald-600 px-2 py-1 text-xs text-white">Confirm</button>
                                            <button wire:click="cancelAction" class="rounded border px-2 py-1 text-xs">Cancel</button>
                                        </div>
                                    </div>
                                @else
                                    <div class="flex flex-wrap gap-2">
                                        @if($hashtag->moderation_state->value !== 'clear')
                                            <button wire:click="startAction({{ $hashtag->id }}, 'clear')" class="rounded border px-2 py-1 text-xs">Clear</button>
                                        @endif
                                        @if($hashtag->moderation_state->value !== 'not_recommended')
                                            <button wire:click="startAction({{ $hashtag->id }}, 'not_recommended')" class="rounded border px-2 py-1 text-xs">De-rank</button>
                                        @endif
                                        @if($hashtag->moderation_state->value !== 'blocked')
                                            <button wire:click="startAction({{ $hashtag->id }}, 'blocked')" class="rounded border border-red-300 px-2 py-1 text-xs text-red-700 dark:text-red-300">Block</button>
                                        @endif
                                    </div>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-8 text-center text-zinc-500">No tags match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $hashtags->links() }}
</div>
