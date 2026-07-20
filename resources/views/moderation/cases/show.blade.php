<x-layouts::app :title="__('Moderation case')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl p-4">
        <livewire:moderation-case-detail :case="$case" />
    </div>
</x-layouts::app>
