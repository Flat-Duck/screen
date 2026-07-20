<div class="flex flex-col gap-4">
    <div class="grid gap-3 md:grid-cols-5">
        <input wire:model.live.debounce.300ms="search" placeholder="Case ID or report details" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900" />
        <select wire:model.live="status" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">All statuses</option><option value="open">Open</option><option value="investigating">Investigating</option><option value="actioned">Actioned</option><option value="dismissed">Dismissed</option>
        </select>
        <select wire:model.live="priority" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">All priorities</option><option value="urgent">Urgent</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option>
        </select>
        <select wire:model.live="reason" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">All reasons</option>@foreach($reasons as $reason)<option value="{{ $reason }}">{{ ucfirst($reason) }}</option>@endforeach
        </select>
        <select wire:model.live="assignee" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900"><option value="">Any assignment</option><option value="unassigned">Unassigned</option></select>
    </div>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase dark:bg-zinc-900"><tr><th class="p-3">Case</th><th class="p-3">Target</th><th class="p-3">Reports</th><th class="p-3">Priority</th><th class="p-3">Status</th><th class="p-3">Assignee</th></tr></thead>
            <tbody class="divide-y dark:divide-zinc-800">
                @forelse($cases as $case)
                    <tr><td class="p-3"><a class="font-medium text-blue-600" href="{{ route('moderation.cases.show', $case) }}">#{{ $case->id }}</a></td><td class="p-3">{{ class_basename($case->target_type) }} #{{ $case->target_id }}</td><td class="p-3">{{ $case->report_count }}</td><td class="p-3">{{ $case->priority->value }}</td><td class="p-3">{{ $case->status->value }}</td><td class="p-3">{{ $case->assignee?->username ?? 'Unassigned' }}</td></tr>
                @empty<tr><td colspan="6" class="p-8 text-center text-zinc-500">No moderation cases.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    {{ $cases->links() }}
</div>
