<x-layouts::app :title="__('User detail')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl p-4">
        <livewire:admin-user-detail :user="$user" />
    </div>
</x-layouts::app>
