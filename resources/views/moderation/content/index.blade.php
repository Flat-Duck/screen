<x-layouts::app :title="__('Content')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl p-4">
        <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Screenshot content</h1>
        <livewire:content-table />
    </div>
</x-layouts::app>
