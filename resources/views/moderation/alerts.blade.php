<x-layouts::app :title="__('Moderation alerts')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl p-4">
        <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Moderation alerts</h1>
        <p class="text-sm text-zinc-500">Detection only — nothing here has acted on anything. Every alert is waiting on a moderator.</p>
        <livewire:moderation-alerts-table />
    </div>
</x-layouts::app>
