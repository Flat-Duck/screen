<?php

namespace App\Livewire;

use App\Enums\ModerationCasePriority;
use App\Enums\ModerationCaseStatus;
use App\Models\Comment;
use App\Models\ModerationCase;
use App\Models\Post;
use App\Models\Scopes\NotArchivedScope;
use App\Models\User;
use App\Services\ModerationCaseService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ModerationCaseDetail extends Component
{
    public int $caseId;

    public string $reason = '';

    public string $note = '';

    public string $priority = 'normal';

    public ?string $flashMessage = null;

    public function mount(ModerationCase $case): void
    {
        $this->caseId = $case->id;
        $this->priority = $case->priority->value;
    }

    public function assignToSelf(ModerationCaseService $service): void
    {
        $this->manage();
        $service->assign($this->case(), $this->admin(), $this->admin(), $this->reason);
        $this->done('Case assigned.');
    }

    public function setPriority(ModerationCaseService $service): void
    {
        $this->manage();
        $service->setPriority($this->case(), $this->admin(), ModerationCasePriority::from($this->priority), $this->reason);
        $this->done('Priority updated.');
    }

    public function addNote(ModerationCaseService $service): void
    {
        $this->manage();
        $service->addNote($this->case(), $this->admin(), $this->note);
        $this->note = '';
        $this->flashMessage = 'Internal note added.';
    }

    public function changeStatus(string $status, ModerationCaseService $service): void
    {
        $this->manage();
        $service->transition($this->case(), $this->admin(), ModerationCaseStatus::from($status), $this->reason);
        $this->done('Case status updated.');
    }

    public function removeContent(ModerationCaseService $service): void
    {
        $this->manage();
        $target = $this->target();
        abort_unless($target instanceof Post || $target instanceof Comment, 422);
        $service->removeContent($target, $this->admin(), $this->reason);
        $this->done('Content removed.');
    }

    public function restorePost(ModerationCaseService $service): void
    {
        $this->manage();
        $target = $this->target();
        abort_unless($target instanceof Post && $target->trashed(), 422);
        $service->restorePost($target, $this->admin(), $this->reason);
        $this->done('Post restored.');
    }

    public function setRecommendation(bool $eligible, ModerationCaseService $service): void
    {
        $this->manage();
        $target = $this->target();
        abort_unless($target instanceof Post, 422);
        $service->setRecommendationEligibility($target, $this->admin(), $eligible, $this->reason);
        $this->done('Recommendation eligibility updated.');
    }

    public function warnAuthor(ModerationCaseService $service): void
    {
        $this->manage();
        $author = $service->authorOf($this->target());
        abort_unless($author !== null, 422);
        $service->warn($author, $this->case(), $this->admin(), $this->reason);
        $this->done('Warning recorded.');
    }

    public function suspendAuthor(bool $ban, ModerationCaseService $service): void
    {
        $this->manage();
        $author = $service->authorOf($this->target());
        abort_unless($author !== null, 422);
        $service->suspend($author, $this->admin(), $this->reason, $ban);
        $this->done($ban ? 'Author banned.' : 'Author suspended.');
    }

    public function render(): View
    {
        $case = $this->case()->load(['assignee', 'reports.reporter', 'notes.author']);

        return view('livewire.moderation-case-detail', ['case' => $case, 'target' => $this->target()]);
    }

    private function case(): ModerationCase
    {
        return ModerationCase::findOrFail($this->caseId);
    }

    private function target(): ?Model
    {
        $case = $this->case();
        if ($case->target_type === Post::class) {
            return Post::withoutGlobalScope(NotArchivedScope::class)->withTrashed()->with(['user', 'media'])->find($case->target_id);
        }

        return $case->target;
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function manage(): void
    {
        Gate::authorize('manageModeration');
    }

    private function done(string $message): void
    {
        $this->reason = '';
        $this->flashMessage = $message;
    }
}
