<div class="flex flex-col gap-4">
    <div class="grid gap-2 md:grid-cols-5">
        <input wire:model.live.debounce.300ms="search" placeholder="Name, exception, fingerprint" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900" />
        <select wire:model.live="status" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900"><option value="">All statuses</option>@foreach(\App\Enums\CrashGroupStatus::cases() as $value)<option value="{{ $value->value }}">{{ ucfirst($value->value) }}</option>@endforeach</select>
        <select wire:model.live="release" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900"><option value="">All releases</option>@foreach($releases as $value)<option>{{ $value }}</option>@endforeach</select>
        <select wire:model.live="os" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900"><option value="">All OS versions</option>@foreach($oses as $value)<option>{{ $value }}</option>@endforeach</select>
        <input wire:model.live.debounce.300ms="device" placeholder="Device maker/model" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900" />
    </div>
    <div class="overflow-x-auto rounded-xl border dark:border-zinc-700"><table class="w-full text-left text-sm"><thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-900"><tr><th class="px-4 py-3">Crash</th><th>Status</th><th>Occurrences</th><th>Users</th><th>Last seen</th><th>Assignee</th></tr></thead><tbody class="divide-y dark:divide-zinc-800">
        @forelse($groups as $group)<tr><td class="px-4 py-3"><a href="{{ route('crash-groups.show', $group) }}" class="font-medium text-blue-600">{{ $group->name }}</a><div class="text-xs text-zinc-500">{{ $group->exception_class ?? 'Unknown exception' }}</div></td><td>{{ ucfirst($group->status->value) }}</td><td>{{ number_format($group->occurrence_count) }}</td><td>{{ number_format($group->affected_user_count) }}</td><td>{{ $group->last_seen_at->diffForHumans() }}</td><td>{{ $group->assignee?->email ?? 'Unassigned' }}</td></tr>
        @empty<tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No crash groups match these filters.</td></tr>@endforelse
    </tbody></table></div>{{ $groups->links() }}
</div>
