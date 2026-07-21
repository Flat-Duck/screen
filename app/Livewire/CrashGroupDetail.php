<?php

namespace App\Livewire;

use App\Enums\CrashGroupStatus;
use App\Models\CrashGroup;
use App\Models\User;
use App\Services\CrashTriageService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

class CrashGroupDetail extends Component
{
    public int $groupId;

    public string $reason = '';

    public string $note = '';

    public string $fixedVersion = '';

    #[Url]
    public string $release = '';

    #[Url]
    public string $os = '';

    #[Url]
    public string $device = '';

    public ?string $flashMessage = null;

    public function mount(CrashGroup $group): void
    {
        $this->groupId = $group->id;
        $this->fixedVersion = (string) $group->fixed_app_version;
    }

    public function assignToSelf(CrashTriageService $service): void
    {
        $this->manage();
        $service->assign($this->group(), $this->admin(), $this->admin(), $this->reason);
        $this->done('Crash group assigned.');
    }

    public function unassign(CrashTriageService $service): void
    {
        $this->manage();
        $service->assign($this->group(), $this->admin(), null, $this->reason);
        $this->done('Crash group unassigned.');
    }

    public function changeStatus(string $status, CrashTriageService $service): void
    {
        $this->manage();
        $service->transition($this->group(), $this->admin(), CrashGroupStatus::from($status), $this->reason, $this->fixedVersion);
        $this->done('Crash status updated.');
    }

    public function addNote(CrashTriageService $service): void
    {
        $this->manage();
        $service->addNote($this->group(), $this->admin(), $this->note);
        $this->note = '';
        $this->flashMessage = 'Internal note added.';
    }

    public function render(): View
    {
        $group = $this->group()->load(['assignee', 'notes.author']);
        $events = $group->events()->with(['device', 'user'])->when($this->release !== '', fn ($q) => $q->where('app_version_name', $this->release))->when($this->os !== '', fn ($q) => $q->where('os_version', $this->os))->when($this->device !== '', fn ($q) => $q->whereHas('device', fn ($q) => $q->where(fn ($q) => $q->where('manufacturer', 'like', "%{$this->device}%")->orWhere('model', 'like', "%{$this->device}%"))));
        $chart = (clone $events)->where('occurred_at', '>=', now()->subDays(13)->startOfDay())->selectRaw('DATE(occurred_at) as day, COUNT(*) as total')->groupBy('day')->orderBy('day')->get();

        return view('livewire.crash-group-detail', ['group' => $group, 'samples' => (clone $events)->latest('received_at')->limit(10)->get(), 'filteredCount' => (clone $events)->count(), 'chart' => $chart]);
    }

    private function group(): CrashGroup
    {
        return CrashGroup::findOrFail($this->groupId);
    }

    private function admin(): User
    { /** @var User $user */ $user = Auth::user();

        return $user;
    }

    private function manage(): void
    {
        Gate::authorize('manageTelemetry');
    }

    private function done(string $message): void
    {
        $this->reason = '';
        $this->flashMessage = $message;
    }
}
