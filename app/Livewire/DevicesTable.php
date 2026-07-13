<?php

namespace App\Livewire;

use App\Models\Device;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class DevicesTable extends Component
{
    use WithPagination;

    /** @var list<string> */
    private const SORTABLE_FIELDS = ['model', 'events_count', 'crashes_count', 'last_seen_at'];

    #[Url]
    public string $search = '';

    #[Url]
    public string $sortField = 'last_seen_at';

    #[Url]
    public string $sortDirection = 'desc';

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORTABLE_FIELDS, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $sortField = in_array($this->sortField, self::SORTABLE_FIELDS, true)
            ? $this->sortField
            : 'last_seen_at';
        $sortDirection = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $devices = Device::query()
            ->with('user')
            ->withCount(['events', 'crashes'])
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    $query->where('device_uuid', 'like', "%{$this->search}%")
                        ->orWhere('manufacturer', 'like', "%{$this->search}%")
                        ->orWhere('brand', 'like', "%{$this->search}%")
                        ->orWhere('model', 'like', "%{$this->search}%");
                });
            })
            ->orderBy($sortField, $sortDirection)
            ->paginate(15);

        return view('livewire.devices-table', ['devices' => $devices]);
    }
}
