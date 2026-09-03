<x-layouts::app :title="__('Tag moderation')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl p-4">
        <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Tag moderation</h1>
        <p class="text-sm text-zinc-500">
            <strong>De-rank</strong> removes a tag from trending, explore and search but leaves its page and posts reachable.
            <strong>Block</strong> also withholds the tag page itself. Neither touches the posts carrying the tag — remove those individually from Content.
        </p>
        <livewire:moderation-tags-table />
    </div>
</x-layouts::app>
