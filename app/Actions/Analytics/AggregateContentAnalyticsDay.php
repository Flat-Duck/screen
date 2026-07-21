<?php

namespace App\Actions\Analytics;

use App\Enums\ContentEventType;
use App\Models\DeviceSession;
use App\Models\Post;
use App\Models\Scopes\NotArchivedScope;
use App\Models\TelemetryEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AggregateContentAnalyticsDay
{
    /** @return array<string, int|bool|string> */
    public function __invoke(CarbonImmutable $day): array
    {
        $start = $day->utc()->startOfDay();
        $end = $start->addDay();
        $date = $start->toDateString();
        $partial = $date === CarbonImmutable::now('UTC')->toDateString();

        $users = [];
        $posts = [];
        $authors = [];
        $topics = [];
        $feedback = [];
        $eventCounts = [];

        $events = DB::table('content_events')
            ->leftJoin('posts', 'posts.id', '=', 'content_events.post_id')
            ->where('content_events.occurred_at', '>=', $start)
            ->where('content_events.occurred_at', '<', $end)
            ->select([
                'content_events.user_id', 'content_events.post_id', 'content_events.author_id',
                'content_events.event_type', 'content_events.surface', 'content_events.candidate_source',
                'content_events.metadata', 'content_events.occurred_at', 'posts.category_id',
            ])->orderBy('content_events.id')->cursor();

        foreach ($events as $event) {
            $type = ContentEventType::from($event->event_type);
            $eventCounts[$type->value] = ($eventCounts[$type->value] ?? 0) + 1;
            $userId = (int) $event->user_id;
            $authorId = $event->author_id !== null ? (int) $event->author_id : null;
            $postId = $event->post_id !== null ? (int) $event->post_id : null;
            $metadata = json_decode((string) ($event->metadata ?? '[]'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $weight = $this->weight($type, $metadata);

            $users[$userId] ??= $this->userRow($date, $userId);
            $users[$userId]['events_count']++;
            if ($postId !== null && $authorId !== null) {
                $users[$userId]['post_ids'][$postId] = true;
            }
            $this->incrementEventMetrics($users[$userId], $type, $metadata);

            if ($postId !== null) {
                $posts[$postId] ??= $this->postRow($date, $postId, $authorId);
                $posts[$postId]['viewer_ids'][$userId] = true;
                $this->incrementEventMetrics($posts[$postId], $type, $metadata);
            }

            if ($authorId !== null && $userId !== $authorId) {
                $key = $userId.':'.$authorId;
                $authors[$key] ??= $this->affinityRow($date, $userId, 'author_id', $authorId);
                $this->incrementAffinity($authors[$key], $type, $weight, (string) $event->occurred_at);

                if ($event->category_id !== null) {
                    $categoryId = (int) $event->category_id;
                    $topicKey = $userId.':'.$categoryId;
                    $topics[$topicKey] ??= $this->affinityRow($date, $userId, 'category_id', $categoryId);
                    $this->incrementAffinity($topics[$topicKey], $type, $weight, (string) $event->occurred_at);
                }
            }

            $source = $event->candidate_source ?? 'unknown';
            $feedbackKey = $event->surface.':'.$source;
            $feedback[$feedbackKey] ??= $this->feedbackRow($date, (string) $event->surface, (string) $source);
            $feedback[$feedbackKey]['user_ids'][$userId] = true;
            $this->incrementFeedback($feedback[$feedbackKey], $type);
        }

        $now = now();
        foreach ($users as &$row) {
            $row['unique_posts'] = count($row['post_ids']);
            unset($row['post_ids']);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);
        foreach ($posts as &$row) {
            $row['unique_viewers'] = count($row['viewer_ids']);
            unset($row['viewer_ids']);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);
        foreach ($feedback as &$row) {
            $row['unique_users'] = count($row['user_ids']);
            unset($row['user_ids']);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);
        $authors = $this->stampRows($authors, $now);
        $topics = $this->stampRows($topics, $now);

        $sessionsStarted = DeviceSession::query()->where('started_at', '>=', $start)->where('started_at', '<', $end)->count();
        $crashedSessions = DB::table('telemetry_events')
            ->join('device_sessions', 'device_sessions.id', '=', 'telemetry_events.device_session_id')
            ->where('telemetry_events.kind', TelemetryEvent::KIND_FATAL_CRASH)
            ->where('device_sessions.started_at', '>=', $start)->where('device_sessions.started_at', '<', $end)
            ->distinct()->count('telemetry_events.device_session_id');

        DB::transaction(function () use ($date, $partial, $users, $posts, $authors, $topics, $feedback, $eventCounts, $sessionsStarted, $crashedSessions, $start, $end, $now): void {
            foreach (['daily_user_activity', 'daily_post_metrics', 'user_author_affinities', 'user_topic_affinities', 'recommendation_feedback_aggregates'] as $table) {
                $dateColumn = match ($table) {
                    'daily_user_activity' => 'activity_date',
                    'user_author_affinities', 'user_topic_affinities' => 'affinity_date',
                    default => 'metric_date',
                };
                DB::table($table)->where($dateColumn, $date)->delete();
            }
            DB::table('daily_product_metrics')->where('metric_date', $date)->delete();
            DB::table('retention_cohort_metrics')->where('activity_date', $date)->delete();

            $this->insertRows('daily_user_activity', $users);
            $this->insertRows('daily_post_metrics', $posts);
            $this->insertRows('user_author_affinities', $authors);
            $this->insertRows('user_topic_affinities', $topics);
            $this->insertRows('recommendation_feedback_aggregates', $feedback);

            DB::table('daily_product_metrics')->insert([
                'metric_date' => $date,
                'daily_active_users' => count($users),
                'registrations' => User::withTrashed()->where('created_at', '>=', $start)->where('created_at', '<', $end)->count(),
                'active_creators' => Post::withoutGlobalScope(NotArchivedScope::class)->withTrashed()->where('created_at', '>=', $start)->where('created_at', '<', $end)->distinct()->count('user_id'),
                'screenshots_published' => DB::table('post_media')->join('posts', 'posts.id', '=', 'post_media.post_id')
                    ->where('posts.created_at', '>=', $start)->where('posts.created_at', '<', $end)->count(),
                'impressions' => (int) ($eventCounts[ContentEventType::Impression->value] ?? 0),
                'opens' => (int) ($eventCounts[ContentEventType::Open->value] ?? 0),
                'saves' => (int) ($eventCounts[ContentEventType::Save->value] ?? 0),
                'follows' => (int) ($eventCounts[ContentEventType::FollowAuthor->value] ?? 0),
                'hides' => (int) ($eventCounts[ContentEventType::Hide->value] ?? 0) + (int) ($eventCounts[ContentEventType::NotInterested->value] ?? 0),
                'reports' => (int) ($eventCounts[ContentEventType::Report->value] ?? 0),
                'sessions_started' => $sessionsStarted,
                'crashed_sessions' => min($sessionsStarted, $crashedSessions),
                'is_partial' => $partial,
                'aggregated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $activeUserIds = array_keys($users);
            for ($daysAgo = 0; $daysAgo <= 30; $daysAgo++) {
                $cohort = $start->subDays($daysAgo);
                $cohortEnd = $cohort->addDay();
                $cohortSize = User::withTrashed()->where('created_at', '>=', $cohort)->where('created_at', '<', $cohortEnd)->count();
                $retained = $activeUserIds === [] ? 0 : User::withTrashed()->whereIn('id', $activeUserIds)
                    ->where('created_at', '>=', $cohort)->where('created_at', '<', $cohortEnd)->count();
                DB::table('retention_cohort_metrics')->insert([
                    'cohort_date' => $cohort->toDateString(),
                    'activity_date' => $date,
                    'day_number' => $daysAgo,
                    'cohort_size' => $cohortSize,
                    'retained_users' => $retained,
                    'is_partial' => $partial,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return ['date' => $date, 'partial' => $partial, 'users' => count($users), 'posts' => count($posts), 'events' => array_sum($eventCounts)];
    }

    /** @return array<string, mixed> */
    private function userRow(string $date, int $userId): array
    {
        return ['activity_date' => $date, 'user_id' => $userId, 'events_count' => 0, 'unique_posts' => 0, 'post_ids' => [], 'impressions' => 0, 'opens' => 0, 'dwell_ms' => 0, 'likes' => 0, 'comments' => 0, 'saves' => 0, 'reposts' => 0, 'shares' => 0, 'follows' => 0, 'negative_feedback' => 0];
    }

    /** @return array<string, mixed> */
    private function postRow(string $date, int $postId, int $authorId): array
    {
        return ['metric_date' => $date, 'post_id' => $postId, 'author_id' => $authorId, 'unique_viewers' => 0, 'viewer_ids' => [], 'impressions' => 0, 'opens' => 0, 'dwell_ms' => 0, 'likes' => 0, 'comments' => 0, 'saves' => 0, 'reposts' => 0, 'shares' => 0, 'hides' => 0, 'not_interested' => 0, 'reports' => 0];
    }

    /** @return array<string, mixed> */
    private function affinityRow(string $date, int $userId, string $targetKey, int $targetId): array
    {
        return ['affinity_date' => $date, 'user_id' => $userId, $targetKey => $targetId, 'score' => 0, 'positive_events' => 0, 'negative_events' => 0, 'impressions' => 0, 'last_event_at' => $date.' 00:00:00'];
    }

    /** @return array<string, mixed> */
    private function feedbackRow(string $date, string $surface, string $source): array
    {
        return ['metric_date' => $date, 'surface' => $surface, 'candidate_source' => $source, 'unique_users' => 0, 'user_ids' => [], 'impressions' => 0, 'opens' => 0, 'saves' => 0, 'follows' => 0, 'hides' => 0, 'not_interested' => 0, 'reports' => 0];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $metadata
     */
    private function incrementEventMetrics(array &$row, ContentEventType $type, array $metadata): void
    {
        $key = match ($type) {
            ContentEventType::Impression => 'impressions', ContentEventType::Open => 'opens', ContentEventType::Like => 'likes',
            ContentEventType::Comment => 'comments', ContentEventType::Save => 'saves', ContentEventType::Repost => 'reposts',
            ContentEventType::Share => 'shares', ContentEventType::FollowAuthor => 'follows', ContentEventType::Hide, ContentEventType::NotInterested, ContentEventType::Report => 'negative_feedback',
            default => null,
        };
        if ($key !== null && array_key_exists($key, $row)) {
            $row[$key]++;
        }
        if ($type === ContentEventType::Dwell && array_key_exists('dwell_ms', $row)) {
            $row['dwell_ms'] += (int) ($metadata['duration_ms'] ?? 0);
        }
        if ($type === ContentEventType::Hide && array_key_exists('hides', $row)) {
            $row['hides']++;
        }
        if ($type === ContentEventType::NotInterested && array_key_exists('not_interested', $row)) {
            $row['not_interested']++;
        }
        if ($type === ContentEventType::Report && array_key_exists('reports', $row)) {
            $row['reports']++;
        }
    }

    /** @param array<string, mixed> $row */
    private function incrementAffinity(array &$row, ContentEventType $type, int $weight, string $occurredAt): void
    {
        $row['score'] += $weight;
        $row['positive_events'] += $weight > 0 ? 1 : 0;
        $row['negative_events'] += $weight < 0 ? 1 : 0;
        $row['impressions'] += $type === ContentEventType::Impression ? 1 : 0;
        $row['last_event_at'] = max($row['last_event_at'], $occurredAt);
    }

    /** @param array<string, mixed> $row */
    private function incrementFeedback(array &$row, ContentEventType $type): void
    {
        $key = match ($type) {
            ContentEventType::Impression => 'impressions', ContentEventType::Open => 'opens', ContentEventType::Save => 'saves',
            ContentEventType::FollowAuthor => 'follows', ContentEventType::Hide => 'hides', ContentEventType::NotInterested => 'not_interested',
            ContentEventType::Report => 'reports', default => null,
        };
        if ($key !== null) {
            $row[$key]++;
        }
    }

    /** @param array<string, mixed> $metadata */
    private function weight(ContentEventType $type, array $metadata): int
    {
        return match ($type) {
            ContentEventType::Open => 1,
            ContentEventType::Dwell => min(3, intdiv((int) ($metadata['duration_ms'] ?? 0), 10_000)),
            ContentEventType::Like => 3,
            ContentEventType::Comment => 4,
            ContentEventType::Save, ContentEventType::Repost => 5,
            ContentEventType::Share => 4,
            ContentEventType::ProfileOpen => 2,
            ContentEventType::FollowAuthor => 6,
            ContentEventType::Hide => -5,
            ContentEventType::NotInterested => -6,
            ContentEventType::Report => -10,
            default => 0,
        };
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    private function insertRows(string $table, array $rows): void
    {
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function stampRows(array $rows, mixed $timestamp): array
    {
        foreach ($rows as &$row) {
            $row['created_at'] = $timestamp;
            $row['updated_at'] = $timestamp;
        }

        return $rows;
    }
}
