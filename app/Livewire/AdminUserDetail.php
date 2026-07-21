<?php

namespace App\Livewire;

use App\Enums\UserRestrictionType;
use App\Models\Post;
use App\Models\Report;
use App\Models\Scopes\NotArchivedScope;
use App\Models\User;
use App\Services\AdminAuditLogger;
use App\Services\UserRestrictionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class AdminUserDetail extends Component
{
    public int $userId;

    public string $restrictionType = 'posting';

    public string $restrictionReason = '';

    public ?int $durationDays = 7;

    public string $supportNote = '';

    public ?string $flashMessage = null;

    public function mount(User $user): void
    {
        $this->userId = $user->id;
    }

    public function createRestriction(UserRestrictionService $service): void
    {
        Gate::authorize('manageModeration');
        $this->validate(['restrictionType' => ['required', 'in:posting,commenting,messaging,recommendation,login'], 'restrictionReason' => ['required', 'string', 'min:3', 'max:2000'], 'durationDays' => ['nullable', 'integer', 'min:1', 'max:3650']]);
        $service->create($this->user(), $this->admin(), UserRestrictionType::from($this->restrictionType), $this->restrictionReason, endsAt: $this->durationDays ? now()->addDays($this->durationDays) : null);
        $this->restrictionReason = '';
        $this->flashMessage = 'Restriction created.';
    }

    public function revokeRestriction(int $restrictionId, string $reason, UserRestrictionService $service): void
    {
        Gate::authorize('manageModeration');
        $restriction = $this->user()->restrictions()->findOrFail($restrictionId);
        $service->revoke($restriction, $this->admin(), $reason);
        $this->flashMessage = 'Restriction revoked.';
    }

    public function extendRestriction(int $restrictionId, int $days, UserRestrictionService $service): void
    {
        Gate::authorize('manageModeration');
        abort_unless($days >= 1 && $days <= 3650, 422);
        $restriction = $this->user()->restrictions()->findOrFail($restrictionId);
        $service->extend($restriction, $this->admin(), now()->addDays($days), "Extended by {$days} days from user detail");
        $this->flashMessage = 'Restriction extended.';
    }

    public function addSupportNote(AdminAuditLogger $audit): void
    {
        Gate::authorize('manageUserSupport');
        $this->validate(['supportNote' => ['required', 'string', 'min:3', 'max:5000']]);
        $note = $this->user()->supportNotes()->create(['author_id' => $this->admin()->id, 'body' => $this->supportNote]);
        $audit->record($this->admin(), 'user.support_note_added', $this->user(), 'Support note #'.$note->id);
        $this->supportNote = '';
        $this->flashMessage = 'Support note added.';
    }

    public function render(): View
    {
        $user = $this->user()->loadCount(['posts' => fn ($query) => $query->withoutGlobalScope(NotArchivedScope::class), 'followers', 'following', 'devices', 'deviceSessions', 'socialAccounts'])
            ->load(['devices' => fn ($query) => $query->latest('last_seen_at')->limit(10), 'deviceSessions' => fn ($query) => $query->latest('started_at')->limit(20), 'restrictions.creator', 'supportNotes.author', 'socialAccounts', 'posts' => fn ($query) => $query->withoutGlobalScope(NotArchivedScope::class)->withTrashed()->latest('id')->limit(12)]);
        $receivedReports = Report::query()->where(function ($reports) use ($user): void {
            $reports->where(fn ($direct) => $direct->where('reportable_type', User::class)->where('reportable_id', $user->id))
                ->orWhere(fn ($posts) => $posts->where('reportable_type', Post::class)->whereIn('reportable_id', $user->posts()->withoutGlobalScope(NotArchivedScope::class)->withTrashed()->select('id')));
        })->latest()->limit(20)->get();
        $moderationHistory = $user->adminAuditLogsAsTarget()->latest()->limit(30)->get();
        $warnings = DB::table('user_warnings')->where('user_id', $user->id)->latest('id')->limit(20)->get();

        return view('livewire.admin-user-detail', compact('user', 'receivedReports', 'moderationHistory', 'warnings'));
    }

    private function user(): User
    {
        return User::withTrashed()->findOrFail($this->userId);
    }

    private function admin(): User
    {
        /** @var User $user */ $user = Auth::user();

        return $user;
    }
}
