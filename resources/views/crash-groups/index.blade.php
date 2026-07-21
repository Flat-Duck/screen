<x-layouts::app :title="__('Crash triage')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="flex items-center justify-between"><div><h1 class="text-xl font-semibold">Crash triage</h1><p class="text-sm text-zinc-500">Fingerprint groups retain workflow state independently of raw-event retention.</p></div><a href="{{ route('events.index') }}" class="text-sm text-blue-600">Raw events →</a></div>
        <livewire:crash-groups-table />
    </div>
</x-layouts::app>
