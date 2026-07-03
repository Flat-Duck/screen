<x-layouts::app :title="__('Events & Crashes')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Events & Crashes</h1>

        <livewire:events-table />
    </div>
</x-layouts::app>
