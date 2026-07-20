<?php

namespace App\Livewire;

use App\Enums\ModerationCaseStatus;
use App\Models\ModerationCase;
use App\Models\Report;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ModerationCasesTable extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'open';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $reason = '';

    #[Url]
    public string $assignee = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'priority', 'reason', 'assignee'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $cases = ModerationCase::query()
            ->with(['assignee'])
            ->when(in_array($this->status, array_column(ModerationCaseStatus::cases(), 'value'), true), fn ($query) => $query->where('status', $this->status))
            ->when($this->priority !== '', fn ($query) => $query->where('priority', $this->priority))
            ->when($this->assignee === 'unassigned', fn ($query) => $query->whereNull('assigned_to'))
            ->when(ctype_digit($this->assignee), fn ($query) => $query->where('assigned_to', (int) $this->assignee))
            ->when($this->reason !== '', fn ($query) => $query->whereHas('reports', fn ($reports) => $reports->where('reason', $this->reason)))
            ->when($this->search !== '', fn ($query) => $query->where(function ($search): void {
                $search->where('id', ctype_digit($this->search) ? (int) $this->search : -1)
                    ->orWhereHas('reports', fn ($reports) => $reports->where('details', 'like', '%'.$this->search.'%'));
            }))
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->latest('last_reported_at')
            ->paginate(20);

        return view('livewire.moderation-cases-table', ['cases' => $cases, 'reasons' => Report::query()->distinct()->pluck('reason')]);
    }
}
