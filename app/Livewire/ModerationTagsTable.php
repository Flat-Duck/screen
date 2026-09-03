<?php

namespace App\Livewire;

use App\Enums\HashtagModerationState;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use App\Services\HashtagModerationService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The lever that did not exist before: tags ranked by recent activity, with the report
 * density underneath them, and a one-click state change. Sorting by recent activity rather
 * than all-time size is deliberate — a tag that has been huge for a year is rarely the
 * problem; the one that appeared this morning usually is.
 */
class ModerationTagsTable extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $state = '';

    #[Url]
    public int $windowDays = 7;

    public ?int $actingOnId = null;

    public string $targetState = '';

    public string $reason = '';

    public ?string $flashMessage = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'state', 'windowDays'], true)) {
            $this->resetPage();
        }
    }

    public function startAction(int $hashtagId, string $targetState): void
    {
        Gate::authorize('manageModeration');
        $this->actingOnId = $hashtagId;
        $this->targetState = $targetState;
        $this->reason = '';
    }

    public function cancelAction(): void
    {
        $this->actingOnId = null;
        $this->targetState = '';
        $this->reason = '';
    }

    public function applyState(HashtagModerationService $moderation): void
    {
        Gate::authorize('manageModeration');

        if ($this->actingOnId === null) {
            return;
        }

        $state = HashtagModerationState::tryFrom($this->targetState);

        if ($state === null) {
            $this->flashMessage = 'Unknown moderation state.';

            return;
        }

        $hashtag = Hashtag::findOrFail($this->actingOnId);
        $moderation->setState($hashtag, $this->currentAdmin(), $state, $this->reason);

        $this->flashMessage = sprintf('#%s set to %s.', $hashtag->name, $state->label());
        $this->cancelAction();
    }

    public function render(): View
    {
        $windowStart = now()->subDays(max(1, $this->windowDays));

        $recentCounts = DB::table('hashtag_post')
            ->where('created_at', '>=', $windowStart)
            ->select('hashtag_id', DB::raw('count(*) as recent_posts'))
            ->groupBy('hashtag_id');

        $hashtags = Hashtag::query()
            // select() must precede withCount(): withCount appends its subquery with
            // addSelect, and a later select() replaces the whole column list — silently
            // nulling posts_count. Guarded by a test.
            ->select('hashtags.*', DB::raw('coalesce(recent.recent_posts, 0) as recent_posts'))
            ->withCount('posts')
            ->with('moderator')
            ->leftJoinSub($recentCounts, 'recent', 'recent.hashtag_id', '=', 'hashtags.id')
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%'.Hashtag::normalize($this->search).'%'))
            ->when($this->state !== '', fn ($query) => $query->where('moderation_state', $this->state))
            ->orderByDesc('recent_posts')
            ->orderByDesc('hashtags.id')
            ->paginate(25);

        return view('livewire.moderation-tags-table', [
            'hashtags' => $hashtags,
            'reportCounts' => $this->reportCountsFor(array_values(array_map('intval', $hashtags->getCollection()->pluck('id')->all())), $windowStart),
            'states' => HashtagModerationState::cases(),
        ]);
    }

    /**
     * Reports filed against posts carrying each tag, for the page's tags only.
     *
     * @param  list<int>  $hashtagIds
     * @return array<int, int>
     */
    private function reportCountsFor(array $hashtagIds, CarbonInterface $since): array
    {
        if ($hashtagIds === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = DB::table('hashtag_post')
            ->join('reports', function ($join): void {
                $join->on('reports.reportable_id', '=', 'hashtag_post.post_id')
                    ->where('reports.reportable_type', '=', Post::class);
            })
            ->whereIn('hashtag_post.hashtag_id', $hashtagIds)
            ->where('reports.created_at', '>=', $since)
            ->groupBy('hashtag_post.hashtag_id')
            ->pluck(DB::raw('count(*)'), 'hashtag_post.hashtag_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return $counts;
    }

    private function currentAdmin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
