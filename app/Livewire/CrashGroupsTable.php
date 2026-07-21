<?php

namespace App\Livewire;

use App\Enums\CrashGroupStatus;
use App\Models\CrashGroup;
use App\Models\TelemetryEvent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class CrashGroupsTable extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $release = '';

    #[Url]
    public string $os = '';

    #[Url]
    public string $device = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $status = in_array($this->status, array_column(CrashGroupStatus::cases(), 'value'), true) ? $this->status : '';
        $groups = CrashGroup::query()->with('assignee')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', "%{$this->search}%")->orWhere('exception_class', 'like', "%{$this->search}%")->orWhere('fingerprint', 'like', "%{$this->search}%")))
            ->when($this->release !== '', fn ($query) => $query->whereHas('events', fn ($query) => $query->where('app_version_name', $this->release)))
            ->when($this->os !== '', fn ($query) => $query->whereHas('events', fn ($query) => $query->where('os_version', $this->os)))
            ->when($this->device !== '', fn ($query) => $query->whereHas('events.device', fn ($query) => $query->where(fn ($query) => $query->where('manufacturer', 'like', "%{$this->device}%")->orWhere('model', 'like', "%{$this->device}%"))))
            ->latest('last_seen_at')->paginate(20);

        return view('livewire.crash-groups-table', [
            'groups' => $groups,
            'releases' => TelemetryEvent::crashes()->whereNotNull('app_version_name')->distinct()->orderByDesc('app_version_code')->limit(50)->pluck('app_version_name'),
            'oses' => TelemetryEvent::crashes()->whereNotNull('os_version')->distinct()->orderBy('os_version')->limit(50)->pluck('os_version'),
        ]);
    }
}
