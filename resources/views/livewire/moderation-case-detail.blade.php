<div class="flex flex-col gap-4">
    @if($flashMessage)<div class="rounded-lg bg-zinc-100 p-3 text-sm dark:bg-zinc-800">{{ $flashMessage }}</div>@endif
    <div class="flex items-center justify-between"><h1 class="text-lg font-semibold">Case #{{ $case->id }}</h1><span>{{ $case->status->value }} · {{ $case->priority->value }} · {{ $case->report_count }} reports</span></div>
    <div class="rounded-xl border p-4 dark:border-zinc-700">
        <div class="font-medium">{{ class_basename($case->target_type) }} #{{ $case->target_id }}</div>
        @if($target instanceof \App\Models\Post)
            <p class="mt-2 text-sm">{{ $target->caption }}</p>
            <a class="text-sm text-blue-600" href="{{ route('moderation.content.show', $target->id) }}">Open safe screenshot preview</a>
        @elseif($target instanceof \App\Models\Comment)<p class="mt-2 text-sm">{{ $target->body }}</p>
        @elseif($target instanceof \App\Models\User)<p class="mt-2 text-sm">{{ $target->username }}</p>
        @else<p class="mt-2 text-sm text-zinc-500">Target was permanently deleted.</p>@endif
    </div>
    @can('manageModeration')
        <div class="grid gap-3 rounded-xl border p-4 dark:border-zinc-700 md:grid-cols-3">
            <input wire:model="reason" placeholder="Required action reason" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900" />
            <select wire:model="priority" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select>
            <button wire:click="setPriority" class="rounded-lg bg-zinc-800 px-3 py-2 text-sm text-white">Set priority</button>
            <button wire:click="assignToSelf" class="rounded-lg bg-blue-600 px-3 py-2 text-sm text-white">Assign to me</button>
            <button wire:click="changeStatus('investigating')" class="rounded-lg bg-blue-600 px-3 py-2 text-sm text-white">Investigate</button>
            <button wire:click="changeStatus('dismissed')" class="rounded-lg bg-zinc-600 px-3 py-2 text-sm text-white">Dismiss</button>
            <button wire:click="changeStatus('actioned')" class="rounded-lg bg-green-700 px-3 py-2 text-sm text-white">Resolve actioned</button>
            @if($target instanceof \App\Models\Post)
                @if($target->trashed())<button wire:click="restorePost" class="rounded-lg bg-green-700 px-3 py-2 text-sm text-white">Restore post</button>@else<button wire:click="removeContent" class="rounded-lg bg-red-700 px-3 py-2 text-sm text-white">Remove post</button>@endif
                <button wire:click="setRecommendation({{ $target->recommendation_eligible ? 'false' : 'true' }})" class="rounded-lg bg-amber-700 px-3 py-2 text-sm text-white">{{ $target->recommendation_eligible ? 'Remove from recommendations' : 'Restore recommendations' }}</button>
            @elseif($target instanceof \App\Models\Comment)<button wire:click="removeContent" class="rounded-lg bg-red-700 px-3 py-2 text-sm text-white">Remove comment</button>@endif
            <button wire:click="warnAuthor" class="rounded-lg bg-amber-700 px-3 py-2 text-sm text-white">Warn author</button>
            <button wire:click="suspendAuthor(false)" class="rounded-lg bg-red-700 px-3 py-2 text-sm text-white">Suspend author</button>
            <button wire:click="suspendAuthor(true)" class="rounded-lg bg-red-900 px-3 py-2 text-sm text-white">Ban author</button>
        </div>
        <div class="rounded-xl border p-4 dark:border-zinc-700"><textarea wire:model="note" placeholder="Internal note" class="w-full rounded-lg border p-3 dark:bg-zinc-900"></textarea><button wire:click="addNote" class="mt-2 rounded-lg bg-zinc-800 px-3 py-2 text-sm text-white">Add note</button></div>
    @endcan
    <div class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-medium">Reports</h2>@foreach($case->reports as $report)<div class="mt-2 text-sm">{{ $report->reason }} by {{ $report->reporter?->username }} — {{ $report->details }}</div>@endforeach</div>
    <div class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-medium">Internal notes</h2>@foreach($case->notes as $note)<div class="mt-2 text-sm">{{ $note->body }} — {{ $note->author?->username }}</div>@endforeach</div>
</div>
