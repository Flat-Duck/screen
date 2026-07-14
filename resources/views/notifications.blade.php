<x-layouts::app :title="__('Notifications')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Notifications</h1>

        <livewire:notification-tester />
    </div>
</x-layouts::app>
