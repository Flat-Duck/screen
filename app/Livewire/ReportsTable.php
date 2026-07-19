<?php

namespace App\Livewire;

use App\Actions\Accounts\SetUserActiveState;
use App\Actions\Posts\DeletePost;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Services\CommentService;
use App\Services\ModerationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin moderation queue for the `Report` model — Report has existed since the reporting
 * API shipped but had no consumer until now; this is the first UI that ever transitions
 * `Report::status` off `pending`. Every consequence action reuses existing Actions/Services
 * (SetUserActiveState, DeletePost, CommentService::deleteComment) rather than reimplementing
 * them, same convention as UsersTable.
 */
class ReportsTable extends Component
{
    use WithPagination;

    #[Url]
    public string $status = Report::STATUS_PENDING;

    #[Url]
    public string $type = '';

    public ?string $flashMessage = null;

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function markReviewed(int $reportId, ModerationService $moderation): void
    {
        $report = Report::findOrFail($reportId);
        $moderation->markReviewed($report, $this->currentAdmin());
        $this->flashMessage = 'Marked reviewed.';
    }

    public function dismiss(int $reportId, ModerationService $moderation): void
    {
        $report = Report::findOrFail($reportId);
        $moderation->dismiss($report, $this->currentAdmin());
        $this->flashMessage = 'Dismissed.';
    }

    public function removeContent(
        int $reportId,
        ModerationService $moderation,
        DeletePost $deletePost,
        CommentService $comments,
    ): void {
        $report = Report::findOrFail($reportId);
        $reportable = $report->reportable;

        if ($reportable instanceof Post) {
            $deletePost($reportable);
        } elseif ($reportable instanceof Comment) {
            $comments->deleteComment($reportable);
        } else {
            $this->flashMessage = 'This report is not about removable content.';

            return;
        }

        $moderation->markReviewed($report, $this->currentAdmin(), 'Content removed.');
        $this->flashMessage = 'Content removed and report marked reviewed.';
    }

    public function suspendAuthor(int $reportId, ModerationService $moderation, SetUserActiveState $setActiveState): void
    {
        $report = Report::findOrFail($reportId);
        $author = $this->authorOf($report->reportable);

        if ($author === null) {
            $this->flashMessage = 'Could not determine who to suspend.';

            return;
        }

        if ($author->is($this->currentAdmin())) {
            $this->flashMessage = "You can't suspend your own account.";

            return;
        }

        $setActiveState($author, false);
        $moderation->markReviewed($report, $this->currentAdmin(), "Suspended {$author->username}.");
        $this->flashMessage = "Suspended {$author->username} and marked reviewed.";
    }

    private function authorOf(?Model $reportable): ?User
    {
        return match (true) {
            $reportable instanceof Post => $reportable->user,
            $reportable instanceof Comment => $reportable->user,
            $reportable instanceof User => $reportable,
            default => null,
        };
    }

    private function currentAdmin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    public function render(): View
    {
        $status = in_array($this->status, [Report::STATUS_PENDING, Report::STATUS_REVIEWED, Report::STATUS_DISMISSED], true)
            ? $this->status
            : '';

        $reportableClass = Report::REPORTABLE_TYPES[$this->type] ?? null;

        $reports = Report::query()
            ->with(['reporter', 'reviewedBy', 'reportable'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($reportableClass !== null, fn ($query) => $query->where('reportable_type', $reportableClass))
            ->latest('id')
            ->paginate(15);

        return view('livewire.reports-table', ['reports' => $reports]);
    }
}
