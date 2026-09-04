@props(['title', 'rows' => []])
<div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
    <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $title }}</h3>
    @forelse($rows as $key => $row)
        <div class="mt-2 flex items-center justify-between text-sm">
            <span class="truncate text-zinc-600 dark:text-zinc-300" title="{{ $key }}">{{ $key }}</span>
            <span class="font-medium">{{ $row['accuracy'] }}% <span class="text-xs font-normal text-zinc-500">(n={{ $row['labels'] }})</span></span>
        </div>
    @empty
        <p class="mt-2 text-sm text-zinc-500">No labels yet.</p>
    @endforelse
</div>
