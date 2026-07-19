<div class="flex flex-col gap-4">
    @if ($flashMessage)
        <div class="rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
            {{ $flashMessage }}
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <select
                wire:model.live="status"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            >
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="reviewed">Reviewed</option>
                <option value="dismissed">Dismissed</option>
            </select>
            <select
                wire:model.live="type"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            >
                <option value="">All types</option>
                <option value="post">Posts</option>
                <option value="comment">Comments</option>
                <option value="user">Users</option>
            </select>
        </div>
        <span class="whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-400">
            {{ $reports->total() }} report{{ $reports->total() === 1 ? '' : 's' }}
        </span>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3">Reported</th>
                    <th class="px-4 py-3">Reporter</th>
                    <th class="px-4 py-3">Reason</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Resolution</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($reports as $report)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900">
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-800 dark:text-zinc-100">
                                {{ class_basename($report->reportable_type) }} #{{ $report->reportable_id }}
                            </div>
                            @if ($report->reportable === null)
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">(deleted)</div>
                            @elseif ($report->reportable instanceof \App\Models\Post)
                                <div class="max-w-xs truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $report->reportable->caption }}</div>
                            @elseif ($report->reportable instanceof \App\Models\Comment)
                                <div class="max-w-xs truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $report->reportable->body }}</div>
                            @elseif ($report->reportable instanceof \App\Models\User)
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $report->reportable->username }}</div>
                            @endif
                            @if ($report->details)
                                <div class="mt-1 max-w-xs truncate text-xs text-zinc-400 dark:text-zinc-500">"{{ $report->details }}"</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $report->reporter?->username ?? '(deleted)' }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ ucfirst($report->reason) }}</td>
                        <td class="px-4 py-3">
                            @if ($report->status === 'pending')
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">pending</span>
                            @elseif ($report->status === 'reviewed')
                                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">reviewed</span>
                            @else
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">dismissed</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                            @if ($report->reviewed_by)
                                <div>{{ $report->resolution_note }}</div>
                                <div>by {{ $report->reviewedBy?->username }}, {{ $report->reviewed_at?->diffForHumans() }}</div>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($report->status === 'pending')
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="dismiss({{ $report->id }})" class="rounded-lg bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200">Dismiss</button>
                                    <button type="button" wire:click="markReviewed({{ $report->id }})" class="rounded-lg bg-blue-100 px-3 py-1 text-xs font-medium text-blue-700 hover:bg-blue-200 dark:bg-blue-900/40 dark:text-blue-300">Mark reviewed</button>
                                    @if ($report->reportable_type !== \App\Models\User::class)
                                        <button type="button" wire:click="removeContent({{ $report->id }})" wire:confirm="Remove this content?" class="rounded-lg bg-red-100 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-200 dark:bg-red-900/40 dark:text-red-300">Remove content</button>
                                    @endif
                                    <button type="button" wire:click="suspendAuthor({{ $report->id }})" wire:confirm="Suspend this user?" class="rounded-lg bg-red-100 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-200 dark:bg-red-900/40 dark:text-red-300">Suspend author</button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No reports.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $reports->links() }}
</div>
