<x-layouts::app :title="__('Screenshot')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl p-4">
        <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Screenshot #{{ $post->id }}</h1>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $post->caption }}</p>
            <p class="mt-2 text-xs text-zinc-500">Author: {{ $post->user?->username }} · Likes: {{ $post->likes_count }} · Comments: {{ $post->comments_count }} · {{ $post->trashed() ? 'Removed' : 'Active' }}</p>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($post->media as $media)
                <img src="{{ route('moderation.media.show', $media) }}" alt="Moderation preview for screenshot {{ $post->id }}" class="max-h-[36rem] w-full rounded-xl object-contain" />
            @endforeach
        </div>
    </div>
</x-layouts::app>
