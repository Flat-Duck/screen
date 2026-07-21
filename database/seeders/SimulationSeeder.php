<?php

namespace Database\Seeders;

use App\Enums\CandidateSource;
use App\Enums\ContentEventType;
use App\Enums\ContentSurface;
use App\Enums\CrashGroupStatus;
use App\Enums\ModerationCasePriority;
use App\Enums\ModerationCaseStatus;
use App\Models\ApiRequestMetric;
use App\Models\ContentEvent;
use App\Models\Conversation;
use App\Models\CrashGroup;
use App\Models\DailyPostMetric;
use App\Models\DailyProductMetric;
use App\Models\DailyUserActivity;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\Experiment;
use App\Models\ExperimentAssignment;
use App\Models\FeatureFlag;
use App\Models\Hashtag;
use App\Models\MediaAnalysis;
use App\Models\Mention;
use App\Models\ModerationCase;
use App\Models\OperationsHealthSnapshot;
use App\Models\Post;
use App\Models\RecommendationExclusion;
use App\Models\RecommendationFeedbackAggregate;
use App\Models\RecommendationFeedSession;
use App\Models\RecommendationPostFeedback;
use App\Models\RecommendationTargetFeedback;
use App\Models\RetentionCohortMetric;
use App\Models\SavedCollection;
use App\Models\ScheduledTaskRun;
use App\Models\ScreenshotCategory;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Models\UserAuthorAffinity;
use App\Models\UserHiddenTerm;
use App\Models\UserRestriction;
use App\Models\UserTopicAffinity;
use App\Services\CrashGroupSynchronizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SimulationSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::query()->whereIn('username', UserSeeder::USERNAMES)->get()->values();
        $posts = Post::query()->where('source_application', PostSeeder::SOURCE)->get()->values();
        $admin = User::query()->where('username', 'testuser')->firstOrFail();

        $this->seedSocialLibrary($users, $posts);
        [$devices, $sessions] = $this->seedDevices($users);
        $this->seedMessaging($users);
        $this->seedModeration($users, $posts, $admin);
        $this->seedTelemetry($users, $devices, $sessions);
        $this->seedAnalytics($users, $posts, $devices, $sessions);
        $this->seedExperiments($users);
        $this->seedOperations($devices);
        $this->seedApiEdgeCases($users, $posts, $devices, $admin);
        $this->seedWorkflowEdges($users, $posts, $admin);
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Post>  $posts
     */
    private function seedSocialLibrary(Collection $users, Collection $posts): void
    {
        foreach ($users as $user) {
            $available = $posts->where('user_id', '!=', $user->id)->shuffle()->values();
            foreach ($available->take(15) as $post) {
                DB::table('saved_posts')->insertOrIgnore(['user_id' => $user->id, 'post_id' => $post->id, 'created_at' => now()->subDays(random_int(0, 40)), 'updated_at' => now()]);
            }
            foreach ($available->slice(15, 5) as $post) {
                DB::table('reposts')->insertOrIgnore(['user_id' => $user->id, 'post_id' => $post->id, 'comment' => fake()->optional()->sentence(), 'created_at' => now()->subDays(random_int(0, 30)), 'updated_at' => now()]);
            }
            foreach (range(0, 2) as $position) {
                $collection = SavedCollection::query()->create(['user_id' => $user->id, 'name' => ['Inspiration', 'Reference', 'Read later'][$position], 'description' => 'Seeded private screenshot collection.', 'position' => $position, 'visibility' => 'private']);
                foreach ($available->slice($position * 4, 4)->values() as $itemPosition => $post) {
                    $collection->items()->create(['post_id' => $post->id, 'note' => fake()->optional()->sentence(), 'position' => $itemPosition]);
                }
            }
            $user->followedHashtags()->syncWithoutDetaching(
                Hashtag::query()->whereIn('name', UserSeeder::SPECIALTIES[(string) $user->username])->pluck('id')
            );
        }

        foreach ($users->take(4) as $index => $user) {
            $target = $users->get($index + 8);
            DB::table('blocks')->insertOrIgnore(['blocker_id' => $user->id, 'blocked_id' => $target->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('mutes')->insertOrIgnore(['muter_id' => $user->id, 'muted_id' => $users->get($index + 12)->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($users->where('account_visibility', 'private')->values() as $index => $target) {
            DB::table('follow_requests')->insertOrIgnore(['requester_id' => $users->get(($index + 5) % $users->count())->id, 'target_id' => $target->id, 'status' => 'pending', 'created_at' => now()->subDays($index), 'updated_at' => now()]);
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{0: Collection<int, Device>, 1: Collection<int, DeviceSession>}
     */
    private function seedDevices(Collection $users): array
    {
        $devices = collect();
        $sessions = collect();
        $versions = [['2.4.0', 240], ['2.3.1', 231], ['2.3.0', 230], ['2.2.0', 220]];
        foreach ($users as $index => $user) {
            [$versionName, $versionCode] = $versions[$index % count($versions)];
            $device = Device::factory()->create(['user_id' => $user->id, 'app_version_name' => $versionName, 'app_version_code' => $versionCode, 'last_seen_at' => now()->subMinutes(random_int(0, 4000))]);
            $session = DeviceSession::factory()->for($device)->for($user)->create(['app_version_name' => $versionName, 'app_version_code' => $versionCode, 'started_at' => now()->subDays(random_int(1, 20)), 'last_seen_at' => now()->subMinutes(random_int(0, 120))]);
            $devices->push($device);
            $sessions->push($session);
        }

        return [$devices, $sessions];
    }

    /** @param Collection<int, User> $users */
    private function seedMessaging(Collection $users): void
    {
        for ($index = 0; $index < 18; $index++) {
            $first = $users[$index % $users->count()];
            $second = $users[($index + 7) % $users->count()];
            $lastMessageAt = now()->subHours(random_int(0, 240));
            $conversation = Conversation::query()->create(['last_message_at' => $lastMessageAt, 'state' => $index % 6 === 0 ? 'requested' : 'active', 'requested_by' => $first->id, 'accepted_at' => $index % 6 === 0 ? null : $lastMessageAt->copy()->subDay()]);
            $conversation->participants()->attach([$first->id => ['last_read_at' => now()->subHours(2)], $second->id => ['last_read_at' => $index % 3 ? now()->subHour() : null]]);
            for ($messageIndex = 0; $messageIndex < random_int(4, 14); $messageIndex++) {
                $conversation->messages()->create(['sender_id' => $messageIndex % 2 ? $first->id : $second->id, 'body' => fake()->sentence(random_int(4, 14)), 'created_at' => $lastMessageAt->copy()->subMinutes($messageIndex * 8), 'updated_at' => $lastMessageAt]);
            }
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Post>  $posts
     */
    private function seedModeration(Collection $users, Collection $posts, User $admin): void
    {
        foreach ($posts->shuffle()->take(14)->values() as $index => $post) {
            $status = [ModerationCaseStatus::Open, ModerationCaseStatus::Investigating, ModerationCaseStatus::Actioned, ModerationCaseStatus::Dismissed][$index % 4];
            $case = ModerationCase::query()->create([
                'target_type' => Post::class, 'target_id' => $post->id,
                'open_key' => in_array($status, [ModerationCaseStatus::Open, ModerationCaseStatus::Investigating], true) ? hash('sha256', 'seed:'.$post->id) : null,
                'status' => $status, 'priority' => [ModerationCasePriority::Low, ModerationCasePriority::Normal, ModerationCasePriority::High, ModerationCasePriority::Urgent][$index % 4],
                'assigned_to' => $index % 3 === 0 ? $admin->id : null, 'report_count' => random_int(1, 8),
                'last_reported_at' => now()->subHours(random_int(1, 200)), 'resolved_at' => in_array($status, [ModerationCaseStatus::Actioned, ModerationCaseStatus::Dismissed], true) ? now()->subHours(random_int(1, 100)) : null,
            ]);
            $case->notes()->create(['author_id' => $admin->id, 'body' => 'Seeded moderation context for dashboard review.']);
            foreach ($users->where('id', '!=', $post->user_id)->shuffle()->take(min(3, $case->report_count)) as $reporter) {
                $case->reports()->create(['reporter_id' => $reporter->id, 'reportable_type' => Post::class, 'reportable_id' => $post->id, 'reason' => fake()->randomElement(['spam', 'harassment', 'sensitive_information', 'misleading']), 'details' => fake()->sentence(), 'status' => $status === ModerationCaseStatus::Dismissed ? 'dismissed' : ($status === ModerationCaseStatus::Actioned ? 'reviewed' : 'pending')]);
            }
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, DeviceSession>  $sessions
     */
    private function seedTelemetry(Collection $users, Collection $devices, Collection $sessions): void
    {
        $fingerprints = [str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), str_repeat('d', 64), str_repeat('e', 64)];
        $sync = app(CrashGroupSynchronizer::class);
        for ($index = 0; $index < 160; $index++) {
            $deviceIndex = $index % $devices->count();
            $fatal = $index % 3 === 0;
            $event = TelemetryEvent::factory()->fatalCrash()->create([
                'device_id' => $devices[$deviceIndex]->id, 'user_id' => $users[$deviceIndex]->id,
                'device_session_id' => $sessions[$deviceIndex]->id,
                'kind' => $fatal ? 'fatal_crash' : 'error', 'is_fatal' => $fatal,
                'crash_fingerprint' => $fingerprints[$index % count($fingerprints)],
                'app_version_name' => $devices[$deviceIndex]->app_version_name, 'app_version_code' => $devices[$deviceIndex]->app_version_code,
                'os_version' => $devices[$deviceIndex]->os_version, 'occurred_at' => now()->subHours(random_int(0, 24 * 20)), 'received_at' => now()->subHours(random_int(0, 24 * 20)),
            ]);
            $sync->sync($event);
        }
        CrashGroup::query()->orderBy('id')->get()->each(function (CrashGroup $group, int $index): void {
            $group->update(['status' => CrashGroupStatus::cases()[$index % count(CrashGroupStatus::cases())], 'fixed_app_version' => $index % 4 === 2 ? '2.4.1' : null]);
        });
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, DeviceSession>  $sessions
     */
    private function seedAnalytics(Collection $users, Collection $posts, Collection $devices, Collection $sessions): void
    {
        for ($day = 29; $day >= 0; $day--) {
            $date = now()->subDays($day)->startOfDay();
            $dau = random_int(12, $users->count());
            $impressions = random_int(900, 2600);
            DailyProductMetric::query()->create(['metric_date' => $date, 'daily_active_users' => $dau, 'registrations' => random_int(0, 5), 'active_creators' => random_int(5, 18), 'screenshots_published' => random_int(8, 35), 'impressions' => $impressions, 'opens' => (int) ($impressions * random_int(20, 42) / 100), 'saves' => (int) ($impressions * random_int(4, 14) / 100), 'follows' => random_int(10, 80), 'hides' => random_int(2, 35), 'reports' => random_int(0, 8), 'sessions_started' => random_int(30, 90), 'crashed_sessions' => random_int(0, 3), 'is_partial' => $day === 0, 'aggregated_at' => now()]);
            foreach ($users->shuffle()->take($dau) as $user) {
                DailyUserActivity::query()->create(['activity_date' => $date, 'user_id' => $user->id, 'events_count' => random_int(5, 90), 'unique_posts' => random_int(2, 30), 'impressions' => random_int(5, 70), 'opens' => random_int(1, 20), 'dwell_ms' => random_int(10_000, 900_000), 'likes' => random_int(0, 12), 'comments' => random_int(0, 5), 'saves' => random_int(0, 8), 'reposts' => random_int(0, 3), 'shares' => random_int(0, 4), 'follows' => random_int(0, 3), 'negative_feedback' => random_int(0, 2)]);
            }
            foreach (CandidateSource::cases() as $source) {
                RecommendationFeedbackAggregate::query()->create(['metric_date' => $date, 'surface' => ContentSurface::ForYouFeed, 'candidate_source' => $source, 'unique_users' => random_int(5, $dau), 'impressions' => random_int(100, 500), 'opens' => random_int(20, 150), 'saves' => random_int(2, 40), 'follows' => random_int(0, 15), 'hides' => random_int(0, 12), 'not_interested' => random_int(0, 8), 'reports' => random_int(0, 3)]);
            }
            RetentionCohortMetric::query()->create(['cohort_date' => $date->copy()->subDay(), 'activity_date' => $date, 'day_number' => 1, 'cohort_size' => 20, 'retained_users' => random_int(7, 17), 'is_partial' => $day === 0]);
        }

        for ($index = 0; $index < 600; $index++) {
            $viewerIndex = $index % $users->count();
            $post = $posts->random();
            ContentEvent::query()->create(['event_uuid' => (string) Str::uuid(), 'user_id' => $users[$viewerIndex]->id, 'device_id' => $devices[$viewerIndex]->id, 'device_session_id' => $sessions[$viewerIndex]->id, 'post_id' => $post->id, 'author_id' => $post->user_id, 'surface' => ContentSurface::ForYouFeed, 'event_type' => ContentEventType::cases()[$index % count(ContentEventType::cases())], 'position' => $index % 30, 'candidate_source' => CandidateSource::cases()[$index % count(CandidateSource::cases())], 'request_id' => (string) Str::uuid(), 'occurred_at' => now()->subMinutes(random_int(0, 40_000)), 'received_at' => now()]);
        }
        foreach ($posts->shuffle()->take(60) as $post) {
            DailyPostMetric::query()->create(['metric_date' => today(), 'post_id' => $post->id, 'author_id' => $post->user_id, 'unique_viewers' => random_int(5, 24), 'impressions' => random_int(20, 200), 'opens' => random_int(5, 80), 'dwell_ms' => random_int(10_000, 800_000), 'likes' => random_int(1, 20), 'comments' => random_int(0, 8), 'saves' => random_int(0, 12), 'reposts' => random_int(0, 5), 'shares' => random_int(0, 6), 'hides' => random_int(0, 3), 'not_interested' => random_int(0, 3), 'reports' => random_int(0, 2)]);
        }
        foreach ($users as $user) {
            foreach ($users->where('id', '!=', $user->id)->shuffle()->take(5) as $author) {
                UserAuthorAffinity::query()->create(['affinity_date' => today(), 'user_id' => $user->id, 'author_id' => $author->id, 'score' => random_int(10, 100), 'positive_events' => random_int(2, 20), 'negative_events' => random_int(0, 3), 'impressions' => random_int(10, 80), 'last_event_at' => now()]);
            }
            foreach (ScreenshotCategory::query()->inRandomOrder()->take(3)->get() as $category) {
                UserTopicAffinity::query()->create(['affinity_date' => today(), 'user_id' => $user->id, 'category_id' => $category->id, 'score' => random_int(10, 100), 'positive_events' => random_int(2, 20), 'negative_events' => random_int(0, 3), 'impressions' => random_int(10, 80), 'last_event_at' => now()]);
            }
        }
    }

    /** @param Collection<int, User> $users */
    private function seedExperiments(Collection $users): void
    {
        FeatureFlag::query()->create(['key' => 'for_you_feed', 'name' => 'For You feed', 'scope' => 'product', 'is_enabled' => true, 'rollout_basis_points' => 10000, 'payload' => ['page_size' => 20]]);
        FeatureFlag::query()->create(['key' => 'sensitive_warning_v2', 'name' => 'Sensitive warning v2', 'scope' => 'product', 'is_enabled' => true, 'rollout_basis_points' => 5000]);
        $experiment = Experiment::query()->create(['key' => 'ranking_diversity_v1', 'name' => 'Ranking diversity', 'scope' => 'recommendation', 'is_enabled' => true, 'allocation_basis_points' => 8000, 'variants' => ['control' => 5000, 'diverse' => 5000], 'salt' => 'seed-ranking-diversity']);
        foreach ($users as $index => $user) {
            ExperimentAssignment::query()->create(['experiment_id' => $experiment->id, 'user_id' => $user->id, 'variant' => $index % 2 ? 'diverse' : 'control', 'experiment_version' => 1, 'assigned_at' => now()->subDays(random_int(0, 20))]);
        }
    }

    /** @param Collection<int, Device> $devices */
    private function seedOperations(Collection $devices): void
    {
        for ($minute = 59; $minute >= 0; $minute--) {
            ApiRequestMetric::query()->create(['minute' => now()->subMinutes($minute)->startOfMinute(), 'requests' => random_int(20, 180), 'errors' => random_int(0, 4), 'rate_limited' => random_int(0, 6), 'total_duration_ms' => random_int(2000, 25_000), 'max_duration_ms' => random_int(100, 900)]);
        }
        OperationsHealthSnapshot::query()->create(['status' => 'healthy', 'checks' => ['database' => ['status' => 'ok'], 'redis' => ['status' => 'ok'], 'storage' => ['status' => 'ok'], 'mail' => ['status' => 'ok'], 'fcm' => ['status' => 'ok']], 'metrics' => ['queue_backlog' => ['default' => 8, 'security' => 1, 'media' => 14], 'failed_jobs_24h' => ['media' => 2], 'security_outbox_backlog' => 3, 'media_processing_failures' => 2, 'cleanup_failures' => 1, 'storage_bytes' => 180_000_000, 'app_versions' => $devices->groupBy('app_version_name')->map(fn ($items, $version) => ['version' => $version, 'devices' => $items->count()])->values()->all()], 'captured_at' => now()]);
        foreach (['operations:capture-health', 'analytics:aggregate --date=today', 'posts:refresh-trending', 'recommendations:refresh-pools', 'security-outbox:dispatch'] as $task) {
            ScheduledTaskRun::query()->create(['task_key' => hash('sha256', $task), 'task_name' => $task, 'status' => 'succeeded', 'runtime_ms' => random_int(40, 2500), 'last_started_at' => now()->subMinutes(random_int(1, 10)), 'last_succeeded_at' => now()->subMinutes(random_int(0, 5))]);
        }
    }

    /**
     * Populate smaller domains so API resources and uncommon dashboard states are testable.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, Device>  $devices
     */
    private function seedApiEdgeCases(Collection $users, Collection $posts, Collection $devices, User $admin): void
    {
        foreach ($devices->take(12) as $index => $device) {
            $device->pushToken()->create([
                'fcm_token' => 'seed-fcm-token-'.$device->id.'-'.Str::random(24),
                'platform' => 'android',
            ]);

            if ($index < 8) {
                $users[$index]->socialAccounts()->create([
                    'provider' => ['google', 'facebook', 'apple'][$index % 3],
                    'provider_user_id' => 'seed-provider-'.$users[$index]->id,
                    'avatar_url' => "https://picsum.photos/seed/avatar-{$users[$index]->username}/160/160",
                ]);
            }
        }

        foreach ($users->take(12) as $index => $user) {
            $post = $posts[($index * 7) % $posts->count()];
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\SeedActivityNotification',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode(['kind' => $index % 2 ? 'like' : 'follow', 'post_id' => $post->id]),
                'read_at' => $index % 3 === 0 ? now()->subHour() : null,
                'created_at' => now()->subHours($index + 1),
                'updated_at' => now()->subHours($index + 1),
            ]);
            Mention::query()->create([
                'mentioner_id' => $post->user_id,
                'mentioned_user_id' => $user->id,
                'mentionable_type' => Post::class,
                'mentionable_id' => $post->id,
            ]);
        }

        foreach ($users->take(6) as $index => $user) {
            $term = UserHiddenTerm::query()->create([
                'user_id' => $user->id,
                'original_value' => ['spoiler', 'crypto promotion', 'politics'][$index % 3],
                'normalized_value' => ['spoiler', 'crypto promotion', 'politics'][$index % 3],
                'normalized_hash' => hash('sha256', ['spoiler', 'crypto promotion', 'politics'][$index % 3]),
                'type' => $index % 2 ? 'phrase' : 'word',
            ]);
            $post = $posts[($index * 11 + 3) % $posts->count()];
            DB::table('content_filter_matches')->insert([
                'user_id' => $user->id,
                'hidden_term_id' => $term->id,
                'filterable_type' => Post::class,
                'filterable_id' => $post->id,
                'reason' => 'hidden_term',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($users->slice(6, 5)->values() as $index => $user) {
            UserRestriction::query()->create([
                'user_id' => $user->id,
                'type' => ['posting', 'commenting', 'messaging', 'recommendation', 'login'][$index],
                'starts_at' => now()->subDays(2),
                'ends_at' => $index === 4 ? null : now()->addDays($index + 1),
                'reason' => 'Seeded restriction for moderation and API testing.',
                'created_by' => $admin->id,
            ]);
        }

        foreach ($users->take(10) as $index => $user) {
            $post = $posts[($index * 13 + 5) % $posts->count()];
            RecommendationPostFeedback::query()->create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'type' => $index % 2 ? RecommendationPostFeedback::HIDDEN : RecommendationPostFeedback::NOT_INTERESTED,
            ]);
            RecommendationTargetFeedback::query()->create([
                'user_id' => $user->id,
                'target_type' => $index % 2 ? RecommendationTargetFeedback::AUTHOR : RecommendationTargetFeedback::HASHTAG,
                'target_id' => $index % 2 ? $post->user_id : $post->hashtags()->value('hashtags.id'),
            ]);
            RecommendationFeedSession::query()->create([
                'request_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'ranking_version' => 'seed-v1',
                'items' => $posts->where('user_id', '!=', $user->id)->shuffle()->take(20)->pluck('id')->all(),
                'expires_at' => now()->addMinutes(20),
            ]);
        }

        foreach ($posts->shuffle()->take(4) as $index => $post) {
            RecommendationExclusion::query()->create([
                'post_id' => $post->id,
                'created_by' => $admin->id,
                'reason' => 'Seeded exclusion for recommendation moderation testing.',
                'expires_at' => $index % 2 ? now()->addWeek() : null,
            ]);
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Post>  $posts
     */
    private function seedWorkflowEdges(Collection $users, Collection $posts, User $admin): void
    {
        foreach ($posts->shuffle()->take(6) as $post) {
            $post->forceFill(['archived_at' => now()->subDays(random_int(1, 20))])->saveQuietly();
        }
        foreach ($posts->shuffle()->take(6) as $post) {
            $post->delete();
        }
        DB::table('security_outbox_messages')->insert(['type' => 'email_changed_notification', 'recipient' => 'seed@example.com', 'payload' => json_encode(['new_email' => 'new@example.com']), 'status' => 'pending', 'attempts' => 1, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('media_cleanup_tasks')->insert(['directory' => 'seed/failed-cleanup', 'status' => 'failed', 'attempts' => 3, 'available_at' => now(), 'last_error' => 'Seeded storage timeout for dashboard testing.', 'created_at' => now(), 'updated_at' => now()]);
        $analysis = MediaAnalysis::query()->create(['token' => (string) Str::uuid(), 'user_id' => $users->first()->id, 'directory' => 'seed/media-analysis', 'status' => 'processing', 'expires_at' => now()->addMinutes(20)]);
        $analysis->items()->create(['position' => 0, 'original_path' => 'https://picsum.photos/seed/analysis/640/1136', 'width' => 640, 'height' => 1136, 'mime_type' => 'image/jpeg', 'size_bytes' => 120000, 'ocr_status' => 'processing', 'safety_status' => 'pending']);
        DB::table('user_support_notes')->insert(['user_id' => $users->last()->id, 'author_id' => $admin->id, 'body' => 'Seeded support note showing recent account recovery context.', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_audit_logs')->insert(['actor_id' => $admin->id, 'action' => 'seed.dashboard_simulation', 'target_type' => User::class, 'target_id' => $users->last()->id, 'reason' => 'Seeded audit event', 'request_id' => (string) Str::uuid(), 'created_at' => now()]);
    }
}
