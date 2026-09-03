<?php

namespace App\Services\Moderation\Detectors;

use App\Enums\HashtagModerationState;
use App\Enums\ModerationAlertSeverity;
use App\Enums\ModerationAlertType;
use App\Models\Hashtag;
use App\Models\ModerationCase;
use App\Models\Post;
use App\Models\Report;
use App\Services\Moderation\AlertDraft;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The proactive detector: it fires on reach, not on complaints. Everything else here waits
 * for a user to report something, which means the worst content is seen by the most people
 * before anyone is told. This one alerts when a post or tag enters the top of the ranking —
 * the moment before it goes wide — so a human can look first.
 *
 * That inverts the usual noise trade-off: a ranked item with no reports is only Info, and
 * `only_when_reported` in config turns the proactive half off entirely if it proves too
 * chatty in practice. Reports, or a tag climbing abnormally fast, escalate it.
 *
 * Reads the same Redis sorted set `posts:refresh-trending` publishes, and fails open the
 * same way FeedService does: no Redis means no post tripwire, never an exception.
 */
class TrendingTripwireDetector implements AlertDetector
{
    public function name(): string
    {
        return 'trending_tripwire';
    }

    /** @return iterable<int, AlertDraft> */
    public function detect(): iterable
    {
        yield from $this->rankedPosts();
        yield from $this->rankedTags();
    }

    /** @return iterable<int, AlertDraft> */
    private function rankedPosts(): iterable
    {
        $topK = (int) config('moderation.alerts.trending_tripwire.post_top_k', 25);
        $reportThreshold = (int) config('moderation.alerts.trending_tripwire.reported_severity_threshold', 1);
        $onlyWhenReported = (bool) config('moderation.alerts.trending_tripwire.only_when_reported', false);

        try {
            /** @var list<string> $ids */
            $ids = Redis::zrevrange((string) config('social.trending.redis_key', 'trending:posts'), 0, $topK - 1);
        } catch (Throwable) {
            return;
        }

        if ($ids === []) {
            return;
        }

        $postIds = array_map('intval', $ids);
        $posts = Post::query()->whereIn('id', $postIds)->with('user')->get()->keyBy('id');

        /** @var array<int, int> $reportCounts */
        $reportCounts = Report::query()
            ->where('reportable_type', Post::class)
            ->whereIn('reportable_id', $postIds)
            ->groupBy('reportable_id')
            ->pluck(DB::raw('count(*)'), 'reportable_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        foreach ($postIds as $rank => $postId) {
            $post = $posts->get($postId);

            if (! $post instanceof Post) {
                continue;
            }

            $reports = $reportCounts[$postId] ?? 0;

            if ($onlyWhenReported && $reports < $reportThreshold) {
                continue;
            }

            $case = ModerationCase::query()->where('open_key', hash('sha256', Post::class.':'.$postId))->first();

            yield new AlertDraft(
                type: ModerationAlertType::TrendingTripwire,
                severity: $this->severityForReports($reports, $reportThreshold),
                title: $reports > 0
                    ? sprintf('Trending post #%d (rank %d) has %d report(s)', $postId, $rank + 1, $reports)
                    : sprintf('Post #%d entered the trending top %d', $postId, $topK),
                dedupeKey: 'post:'.$postId,
                target: $post,
                context: [
                    'surface' => 'post',
                    'post_id' => $postId,
                    'rank' => $rank + 1,
                    'top_k' => $topK,
                    'reports' => $reports,
                    'author_id' => $post->user_id,
                    'author_username' => $post->user?->username,
                    'recommendation_eligible' => (bool) $post->getAttribute('recommendation_eligible'),
                ],
                moderationCase: $case,
            );
        }
    }

    /**
     * Mirrors HashtagService::trending()'s ranking (recent posts from publicly-visible
     * authors) and adds the two things a moderator needs that the public ranking does not
     * carry: how fast the tag is climbing versus the previous window, and how many reports
     * sit on the posts underneath it.
     *
     * @return iterable<int, AlertDraft>
     */
    private function rankedTags(): iterable
    {
        $topK = (int) config('moderation.alerts.trending_tripwire.tag_top_k', 10);
        $windowDays = (int) config('moderation.alerts.trending_tripwire.tag_window_days', 7);
        $velocityMultiplier = (float) config('moderation.alerts.trending_tripwire.tag_velocity_multiplier', 3.0);
        $velocityMinPosts = (int) config('moderation.alerts.trending_tripwire.tag_velocity_min_posts', 10);
        $reportThreshold = (int) config('moderation.alerts.trending_tripwire.reported_severity_threshold', 1);
        $onlyWhenReported = (bool) config('moderation.alerts.trending_tripwire.only_when_reported', false);

        $windowStart = now()->subDays($windowDays);
        $priorStart = now()->subDays($windowDays * 2);

        $eligiblePostIds = Post::query()
            ->fromPubliclyVisibleAuthors()
            ->whereNull('archived_at')
            ->select('id');

        /** @var Collection<int, int> $ranked */
        $ranked = DB::table('hashtag_post')
            ->whereIn('post_id', $eligiblePostIds)
            ->where('created_at', '>=', $windowStart)
            // An already-moderated tag is not news — it has been dealt with.
            ->whereIn('hashtag_id', Hashtag::query()->where('moderation_state', HashtagModerationState::Clear->value)->select('id'))
            ->select('hashtag_id', DB::raw('count(*) as recent_count'))
            ->groupBy('hashtag_id')
            ->orderByDesc('recent_count')
            ->limit($topK)
            ->pluck('recent_count', 'hashtag_id')
            ->map(fn ($count): int => (int) $count);

        if ($ranked->isEmpty()) {
            return;
        }

        $tagIds = $ranked->keys()->map(fn ($id): int => (int) $id)->all();

        /** @var array<int, int> $priorCounts */
        $priorCounts = DB::table('hashtag_post')
            ->whereIn('hashtag_id', $tagIds)
            ->whereBetween('created_at', [$priorStart, $windowStart])
            ->groupBy('hashtag_id')
            ->pluck(DB::raw('count(*)'), 'hashtag_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        /** @var array<int, int> $reportCounts */
        $reportCounts = DB::table('hashtag_post')
            ->join('reports', function ($join): void {
                $join->on('reports.reportable_id', '=', 'hashtag_post.post_id')
                    ->where('reports.reportable_type', '=', Post::class);
            })
            ->whereIn('hashtag_post.hashtag_id', $tagIds)
            ->where('reports.created_at', '>=', $windowStart)
            ->groupBy('hashtag_post.hashtag_id')
            ->pluck(DB::raw('count(*)'), 'hashtag_post.hashtag_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $hashtags = Hashtag::query()->whereIn('id', $tagIds)->get()->keyBy('id');
        $rank = 0;

        foreach ($ranked as $hashtagId => $recentCount) {
            $rank++;
            $hashtag = $hashtags->get((int) $hashtagId);

            if (! $hashtag instanceof Hashtag) {
                continue;
            }

            $reports = $reportCounts[(int) $hashtagId] ?? 0;
            $prior = $priorCounts[(int) $hashtagId] ?? 0;
            $velocity = $prior > 0 ? round($recentCount / $prior, 2) : (float) $recentCount;
            $spiking = $recentCount >= $velocityMinPosts && $velocity >= $velocityMultiplier;

            if ($onlyWhenReported && $reports < $reportThreshold) {
                continue;
            }

            $severity = $this->severityForReports($reports, $reportThreshold);

            if ($severity === ModerationAlertSeverity::Info && $spiking) {
                $severity = ModerationAlertSeverity::Warning;
            }

            yield new AlertDraft(
                type: ModerationAlertType::TrendingTripwire,
                severity: $severity,
                title: match (true) {
                    $reports > 0 => sprintf('Trending tag #%s has %d report(s) on its posts', $hashtag->name, $reports),
                    $spiking => sprintf('Tag #%s is spiking (%dx prior window)', $hashtag->name, (int) $velocity),
                    default => sprintf('Tag #%s entered the trending top %d', $hashtag->name, $topK),
                },
                dedupeKey: 'tag:'.$hashtag->id,
                target: $hashtag,
                context: [
                    'surface' => 'hashtag',
                    'hashtag_id' => $hashtag->id,
                    'hashtag_name' => $hashtag->name,
                    'rank' => $rank,
                    'recent_posts' => $recentCount,
                    'prior_window_posts' => $prior,
                    'velocity' => $velocity,
                    'reports' => $reports,
                    'window_days' => $windowDays,
                ],
            );
        }
    }

    /**
     * Reach alone is Info — worth a glance, not an interruption. Reports on something already
     * ranked are what make it urgent.
     */
    private function severityForReports(int $reports, int $threshold): ModerationAlertSeverity
    {
        return match (true) {
            $reports >= $threshold * 3 => ModerationAlertSeverity::Critical,
            $reports >= $threshold => ModerationAlertSeverity::Warning,
            default => ModerationAlertSeverity::Info,
        };
    }
}
