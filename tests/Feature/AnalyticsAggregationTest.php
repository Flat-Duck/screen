<?php

namespace Tests\Feature;

use App\Actions\Analytics\AggregateContentAnalyticsDay;
use App\Models\ContentEvent;
use App\Models\DailyPostMetric;
use App\Models\DailyProductMetric;
use App\Models\DailyUserActivity;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\RecommendationFeedbackAggregate;
use App\Models\RetentionCohortMetric;
use App\Models\ScreenshotCategory;
use App\Models\User;
use App\Models\UserAuthorAffinity;
use App\Models\UserTopicAffinity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsAggregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_daily_aggregation_builds_product_post_activity_affinity_feedback_and_retention_rows(): void
    {
        [$day, $viewer, $post, $session] = $this->fixture();

        $this->record($viewer, $post, $session, 'impression');
        $this->record($viewer, $post, $session, 'open');
        $this->record($viewer, $post, $session, 'dwell', ['duration_ms' => 20_000]);
        $this->record($viewer, $post, $session, 'save');
        $this->record($viewer, $post, $session, 'follow_author', postBased: false);
        $this->record($viewer, $post, $session, 'hide', ['reason' => 'not_relevant']);
        $this->record($viewer, $post, $session, 'report');

        $result = app(AggregateContentAnalyticsDay::class)($day);

        $this->assertSame(7, $result['events']);
        $product = DailyProductMetric::firstOrFail();
        $this->assertFalse($product->is_partial);
        $this->assertSame(1, $product->daily_active_users);
        $this->assertSame(1, $product->screenshots_published);
        $this->assertSame(1, $product->impressions);
        $this->assertSame(1, $product->opens);
        $this->assertSame(1, $product->saves);
        $this->assertSame(1, $product->follows);
        $this->assertSame(1, $product->hides);
        $this->assertSame(1, $product->reports);

        $activity = DailyUserActivity::firstOrFail();
        $this->assertSame(7, $activity->events_count);
        $this->assertSame(1, $activity->unique_posts);
        $this->assertSame(20_000, $activity->dwell_ms);

        $postMetric = DailyPostMetric::firstOrFail();
        $this->assertSame(1, $postMetric->unique_viewers);
        $this->assertSame(6, $postMetric->impressions + $postMetric->opens + $postMetric->saves + $postMetric->hides + $postMetric->reports + ($postMetric->dwell_ms > 0 ? 1 : 0));

        $authorAffinity = UserAuthorAffinity::firstOrFail();
        $this->assertSame(-1, $authorAffinity->score);
        $this->assertSame(4, $authorAffinity->positive_events);
        $this->assertSame(2, $authorAffinity->negative_events);

        $topicAffinity = UserTopicAffinity::firstOrFail();
        $this->assertSame(-7, $topicAffinity->score);
        $this->assertSame($post->category_id, $topicAffinity->category_id);

        $feedback = RecommendationFeedbackAggregate::query()->where('candidate_source', 'trending')->firstOrFail();
        $this->assertSame(1, $feedback->unique_users);
        $this->assertSame(1, $feedback->impressions);
        $this->assertSame(1, $feedback->hides);

        $retention = RetentionCohortMetric::query()->where('day_number', 1)->firstOrFail();
        $this->assertSame(1, $retention->cohort_size);
        $this->assertSame(1, $retention->retained_users);
    }

    public function test_rebuild_is_idempotent_and_includes_late_events(): void
    {
        [$day, $viewer, $post, $session] = $this->fixture();
        $this->record($viewer, $post, $session, 'impression');
        $aggregate = app(AggregateContentAnalyticsDay::class);

        $aggregate($day);
        $aggregate($day);
        $this->assertDatabaseCount('daily_product_metrics', 1);
        $this->assertSame(1, DailyProductMetric::firstOrFail()->impressions);

        $this->record($viewer, $post, $session, 'impression');
        $aggregate($day);
        $this->assertDatabaseCount('daily_product_metrics', 1);
        $this->assertSame(2, DailyProductMetric::firstOrFail()->impressions);
        $this->assertSame(2, DailyPostMetric::firstOrFail()->impressions);
    }

    public function test_command_supports_repair_ranges_and_marks_current_utc_day_partial(): void
    {
        $this->artisan('analytics:aggregate --from=2026-07-18 --to=2026-07-19')->assertSuccessful();
        $this->assertDatabaseCount('daily_product_metrics', 2);
        $this->assertFalse(DailyProductMetric::query()->where('metric_date', '2026-07-19')->firstOrFail()->is_partial);

        $this->artisan('analytics:aggregate --date=today')->assertSuccessful();
        $this->assertTrue(DailyProductMetric::query()->where('metric_date', '2026-07-20')->firstOrFail()->is_partial);
    }

    /** @return array{CarbonImmutable, User, Post, DeviceSession} */
    private function fixture(): array
    {
        $day = CarbonImmutable::parse('2026-07-19', 'UTC');
        $viewer = User::factory()->create();
        $viewer->forceFill(['created_at' => $day->subDay()->addHours(8), 'updated_at' => $day->subDay()->addHours(8)])->save();
        $author = User::factory()->create();
        $category = ScreenshotCategory::query()->where('slug', 'code')->firstOrFail();
        $post = Post::factory()->for($author)->create(['category_id' => $category->id]);
        $post->forceFill(['created_at' => $day->addHours(9), 'updated_at' => $day->addHours(9)])->save();
        PostMedia::factory()->for($post)->create();
        User::factory()->create()->forceFill(['created_at' => $day->addHours(10), 'updated_at' => $day->addHours(10)])->save();
        $device = Device::factory()->create(['user_id' => $viewer->id]);
        $session = DeviceSession::factory()->for($viewer)->for($device)->create([
            'started_at' => $day->addHours(7),
            'last_seen_at' => $day->addHours(12),
        ]);

        return [$day, $viewer, $post, $session];
    }

    /** @param array<string, mixed> $metadata */
    private function record(User $viewer, Post $post, DeviceSession $session, string $type, array $metadata = [], bool $postBased = true): ContentEvent
    {
        return ContentEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $viewer->id,
            'device_id' => $session->device_id,
            'device_session_id' => $session->id,
            'post_id' => $postBased ? $post->id : null,
            'author_id' => $post->user_id,
            'surface' => $postBased ? 'for_you_feed' : 'profile',
            'event_type' => $type,
            'position' => 0,
            'candidate_source' => $postBased ? 'trending' : 'profile',
            'request_id' => (string) Str::uuid(),
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => CarbonImmutable::parse('2026-07-19 12:00:00', 'UTC'),
            'received_at' => CarbonImmutable::parse('2026-07-19 12:01:00', 'UTC'),
        ]);
    }
}
