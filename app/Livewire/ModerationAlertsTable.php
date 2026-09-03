<?php

namespace App\Livewire;

use App\Enums\ModerationAlertSeverity;
use App\Enums\ModerationAlertState;
use App\Enums\ModerationAlertType;
use App\Models\ModerationAlert;
use App\Models\User;
use App\Services\Moderation\ModerationAlertService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The alert queue. Viewing needs `viewModeration`; acknowledging or resolving needs
 * `manageModeration` — a read_only_auditor can see what fired but cannot clear it.
 */
class ModerationAlertsTable extends Component
{
    use WithPagination;

    #[Url]
    public string $state = 'active';

    #[Url]
    public string $type = '';

    #[Url]
    public string $severity = '';

    /** Resolution reason, keyed by alert id — resolving demands a written reason. */
    public string $resolveReason = '';

    public ?int $resolvingId = null;

    public ?string $flashMessage = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['state', 'type', 'severity'], true)) {
            $this->resetPage();
        }
    }

    public function acknowledge(int $alertId, ModerationAlertService $alerts): void
    {
        Gate::authorize('manageModeration');
        $alerts->acknowledge(ModerationAlert::findOrFail($alertId), $this->currentAdmin());
        $this->flashMessage = 'Alert acknowledged.';
    }

    public function startResolve(int $alertId): void
    {
        Gate::authorize('manageModeration');
        $this->resolvingId = $alertId;
        $this->resolveReason = '';
    }

    public function cancelResolve(): void
    {
        $this->resolvingId = null;
        $this->resolveReason = '';
    }

    public function resolve(ModerationAlertService $alerts): void
    {
        Gate::authorize('manageModeration');

        if ($this->resolvingId === null) {
            return;
        }

        $alerts->resolve(ModerationAlert::findOrFail($this->resolvingId), $this->currentAdmin(), $this->resolveReason);
        $this->resolvingId = null;
        $this->resolveReason = '';
        $this->flashMessage = 'Alert resolved.';
    }

    public function render(): View
    {
        $alerts = ModerationAlert::query()
            ->with(['acknowledger', 'moderationCase'])
            ->when($this->state === 'active', fn ($query) => $query->active())
            ->when(in_array($this->state, array_column(ModerationAlertState::cases(), 'value'), true), fn ($query) => $query->where('state', $this->state))
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->when($this->severity !== '', fn ($query) => $query->where('severity', $this->severity))
            ->orderByRaw("case severity when 'critical' then 1 when 'warning' then 2 else 3 end")
            ->latest('last_detected_at')
            ->paginate(25);

        return view('livewire.moderation-alerts-table', [
            'alerts' => $alerts,
            'types' => ModerationAlertType::cases(),
            'severities' => ModerationAlertSeverity::cases(),
        ]);
    }

    private function currentAdmin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
