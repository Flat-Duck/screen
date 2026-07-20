<div class="flex flex-col gap-4">
    <div class="grid gap-3 md:grid-cols-4">
        <input wire:model.live.debounce.300ms="search" placeholder="Caption or post ID" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900" />
        <select wire:model.live="state" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900"><option value="">Any state</option><option value="active">Active</option><option value="removed">Removed</option></select>
        <select wire:model.live="recommendation" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900"><option value="">Any recommendation state</option><option value="eligible">Eligible</option><option value="ineligible">Ineligible</option></select>
        <select wire:model.live="reported" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900"><option value="">Any report state</option><option value="yes">Reported</option></select>
    </div>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse($posts as $post)
            <a href="{{ route('moderation.content.show', $post->id) }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="font-medium">Screenshot #{{ $post->id }}</div>
                <div class="mt-1 truncate text-sm text-zinc-500">{{ $post->caption }}</div>
                <div class="mt-2 text-xs text-zinc-500">{{ $post->user?->username }} · {{ $post->trashed() ? 'Removed' : 'Active' }} · {{ $post->recommendation_eligible ? 'Recommended' : 'Excluded' }}</div>
            </a>
        @empty<p class="text-zinc-500">No screenshots.</p>@endforelse
    </div>
    {{ $posts->links() }}
</div>
