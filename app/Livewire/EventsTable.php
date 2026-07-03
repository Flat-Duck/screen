<?php

namespace App\Livewire;

use App\Models\TelemetryEvent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EventsTable extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** '' | 'event' | 'error' | 'fatal_crash' */
    #[Url]
    public string $kind = '';

    #[Url]
    public string $sortField = 'received_at';

    #[Url]
    public string $sortDirection = 'desc';

    public function sortBy(string $field): void
    {
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

    public function updatingKind(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $events = TelemetryEvent::query()
            ->with('device')
            ->when($this->kind !== '', fn ($query) => $query->where('kind', $this->kind))
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    $query->where('name', 'like', "%{$this->search}%")
                        ->orWhere('exception_class', 'like', "%{$this->search}%")
                        ->orWhereHas('device', function ($query) {
                            $query->where('device_uuid', 'like', "%{$this->search}%")
                                ->orWhere('model', 'like', "%{$this->search}%");
                        });
                });
            })
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate(20);

        return view('livewire.events-table', ['events' => $events]);
    }
}
