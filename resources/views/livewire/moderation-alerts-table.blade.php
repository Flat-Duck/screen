<div class="flex flex-col gap-4" wire:poll.60s>
    @if($flashMessage)
        <div class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">{{ $flashMessage }}</div>
    @endif

    <div class="grid gap-3 md:grid-cols-3">
        <select wire:model.live="state" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="active">Active (open + acknowledged)</option>
            <option value="open">Open</option>
            <option value="acknowledged">Acknowledged</option>
            <option value="resolved">Resolved</option>
            <option value="">All</option>
        </select>
        <select wire:model.live="type" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">All types</option>
            @foreach($types as $alertType)<option value="{{ $alertType->value }}">{{ $alertType->label() }}</option>@endforeach
        </select>
        <select wire:model.live="severity" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">All severities</option>
            @foreach($severities as $level)<option value="{{ $level->value }}">{{ ucfirst($level->value) }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase dark:bg-zinc-900">
                <tr>
                    <th class="p-3">Severity</th>
                    <th class="p-3">Alert</th>
                    <th class="p-3">Type</th>
                    <th class="p-3">Detected</th>
                    <th class="p-3">State</th>
                    <th class="p-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y dark:divide-zinc-800">
                @forelse($alerts as $alert)
                    <tr @class(['bg-red-50/60 dark:bg-red-950/20' => $alert->severity->value === 'critical'])>
                        <td class="p-3">
                            <span @class([
                                'rounded px-2 py-0.5 text-xs font-medium',
                                'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' => $alert->severity->value === 'critical',
                                'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200' => $alert->severity->value === 'warning',
                                'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' => $alert->severity->value === 'info',
                            ])>{{ ucfirst($alert->severity->value) }}</span>
                        </td>
                        <td class="p-3">
                            <div class="font-medium text-zinc-800 dark:text-zinc-100">{{ $alert->title }}</div>
                            @if($alert->moderation_case_id)
                                <a class="text-xs text-blue-600" href="{{ route('moderation.cases.show', $alert->moderation_case_id) }}">Case #{{ $alert->moderation_case_id }}</a>
                            @endif
                            @if($alert->context)
                                <div class="mt-1 text-xs text-zinc-500">
                                    @foreach($alert->context as $key => $value)
                                        <span class="mr-2">{{ $key }}: {{ is_scalar($value) ? $value : json_encode($value) }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="p-3 text-zinc-600 dark:text-zinc-400">{{ $alert->type->label() }}</td>
                        <td class="p-3 text-zinc-600 dark:text-zinc-400">{{ $alert->last_detected_at?->diffForHumans() ?? '—' }}</td>
                        <td class="p-3">
                            {{ ucfirst($alert->state->value) }}
                            @if($alert->acknowledger)<div class="text-xs text-zinc-500">by {{ $alert->acknowledger->username }}</div>@endif
                        </td>
                        <td class="p-3">
                            @can('manageModeration')
                                @if($resolvingId === $alert->id)
                                    <div class="flex flex-col gap-2">
                                        <input wire:model="resolveReason" placeholder="Resolution reason" class="rounded border px-2 py-1 text-xs dark:bg-zinc-900" />
                                        @error('reason')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                                        <div class="flex gap-2">
                                            <button wire:click="resolve" class="rounded bg-emerald-600 px-2 py-1 text-xs text-white">Confirm</button>
                                            <button wire:click="cancelResolve" class="rounded border px-2 py-1 text-xs">Cancel</button>
                                        </div>
                                    </div>
                                @else
                                    <div class="flex gap-2">
                                        @if($alert->state->value === 'open')
                                            <button wire:click="acknowledge({{ $alert->id }})" class="rounded border px-2 py-1 text-xs">Acknowledge</button>
                                        @endif
                                        @if($alert->state->value !== 'resolved')
                                            <button wire:click="startResolve({{ $alert->id }})" class="rounded border px-2 py-1 text-xs">Resolve</button>
                                        @endif
                                    </div>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-8 text-center text-zinc-500">Nothing has tripped. The detector runs every five minutes.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $alerts->links() }}
</div>
