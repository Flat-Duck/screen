<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\HashtagModerationState;
use App\Enums\ModerationAlertSeverity;
use App\Enums\ModerationAlertState;
use App\Enums\ModerationAlertType;
use App\Enums\ModerationCasePriority;
use App\Enums\ModerationCaseStatus;
use App\Livewire\ModerationAlertsTable;
use App\Models\Hashtag;
use App\Models\ModerationAlert;
use App\Models\ModerationCase;
use App\Models\Post;
use App\Models\User;
use App\Services\Moderation\AlertDraft;
use App\Services\Moderation\Detectors\AlertDetector;
use App\Services\Moderation\Detectors\ReportSpikeDetector;
use App\Services\Moderation\ModerationAlertService;
use App\Services\ModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ModerationAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The post half of the tripwire reads the Redis sorted set `posts:refresh-trending`
        // publishes. Faked here so the suite stays infrastructure-independent; the
        // fail-open path is asserted separately.
        Redis::shouldReceive('zrevrange')->andReturn([])->byDefault();
    }

    /** @param  list<User>|int  $reporters */
    private function reportPost(Post $post, int $times, string $reason = 'spam'): void
    {
        $moderation = app(ModerationService::class);

        for ($i = 0; $i < $times; $i++) {
            $moderation->report(User::factory()->create(), 'post', $post->id, $reason, null);
        }
    }

    public function test_reports_below_the_spike_threshold_raise_nothing(): void
    {
        config([
            'moderation.alerts.report_spike.threshold' => 5,
            // Isolated from the brigading rule, which legitimately fires on the same four
            // reports: four fresh accounts converging on one post is a raid even when the
            // volume is too low to be a spike.
            'moderation.alerts.brigading.young_reporter_threshold' => 99,
        ]);
        $this->reportPost(Post::factory()->create(), 4);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertDatabaseCount('moderation_alerts', 0);
    }

    public function test_a_report_spike_raises_a_warning_and_escalates_to_critical(): void
    {
        config([
            'moderation.alerts.report_spike.threshold' => 5,
            'moderation.alerts.report_spike.critical_threshold' => 8,
        ]);
        $post = Post::factory()->create();
        $this->reportPost($post, 5);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $alert = ModerationAlert::query()->where('type', ModerationAlertType::ReportSpike->value)->sole();
        $this->assertSame(ModerationAlertSeverity::Warning, $alert->severity);
        $this->assertSame(5, $alert->context['recent_reports']);

        $this->reportPost($post, 3);
        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $alert->refresh();
        $this->assertSame(ModerationAlertSeverity::Critical, $alert->severity);
        $this->assertSame(8, $alert->context['recent_reports']);
    }

    public function test_severity_never_ratchets_back_down_while_an_alert_is_open(): void
    {
        config([
            'moderation.alerts.report_spike.threshold' => 2,
            'moderation.alerts.report_spike.critical_threshold' => 2,
        ]);
        $post = Post::factory()->create();
        $this->reportPost($post, 2);
        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        // Same condition re-detected under a rule that would now only warrant a warning:
        // a moderator arriving late still needs to see how bad it got.
        config(['moderation.alerts.report_spike.critical_threshold' => 99]);
        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertSame(
            ModerationAlertSeverity::Critical,
            ModerationAlert::query()->where('type', ModerationAlertType::ReportSpike->value)->sole()->severity,
        );
    }

    public function test_repeated_detection_refreshes_one_alert_rather_than_duplicating(): void
    {
        config(['moderation.alerts.report_spike.threshold' => 3]);
        $this->reportPost(Post::factory()->create(), 3);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();
        $this->artisan('moderation:detect-alerts')->assertSuccessful();
        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertSame(1, ModerationAlert::query()->where('type', ModerationAlertType::ReportSpike->value)->count());
    }

    public function test_resolving_frees_the_dedupe_key_so_the_condition_can_alert_again(): void
    {
        config(['moderation.alerts.report_spike.threshold' => 3]);
        $post = Post::factory()->create();
        $this->reportPost($post, 3);
        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $first = ModerationAlert::query()->where('type', ModerationAlertType::ReportSpike->value)->sole();
        app(ModerationAlertService::class)
            ->resolve($first, User::factory()->create(['is_admin' => true]), 'Reviewed, content stays up.');

        $this->assertNull($first->fresh()?->open_key);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertSame(2, ModerationAlert::query()->where('type', ModerationAlertType::ReportSpike->value)->count());
    }

    public function test_sla_breach_uses_the_budget_for_the_case_priority(): void
    {
        config(['moderation.alerts.sla.hours' => ['urgent' => 2, 'high' => 12, 'normal' => 48, 'low' => 168]]);

        $breaching = $this->caseAged(ModerationCasePriority::Urgent, 5);
        $withinBudget = $this->caseAged(ModerationCasePriority::Normal, 5);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $alerts = ModerationAlert::query()->where('type', ModerationAlertType::SlaBreach->value)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame($breaching->id, $alerts->first()?->moderation_case_id);
        $this->assertNotSame($withinBudget->id, $alerts->first()?->moderation_case_id);
    }

    public function test_sla_breach_escalates_to_critical_past_the_multiplier(): void
    {
        config([
            'moderation.alerts.sla.hours' => ['urgent' => 2, 'high' => 12, 'normal' => 48, 'low' => 168],
            'moderation.alerts.sla.critical_multiplier' => 2.0,
        ]);
        $this->caseAged(ModerationCasePriority::Urgent, 9);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertSame(
            ModerationAlertSeverity::Critical,
            ModerationAlert::query()->where('type', ModerationAlertType::SlaBreach->value)->sole()->severity,
        );
    }

    public function test_a_resolved_case_never_breaches_its_sla(): void
    {
        config(['moderation.alerts.sla.hours' => ['urgent' => 2, 'high' => 12, 'normal' => 48, 'low' => 168]]);
        $case = $this->caseAged(ModerationCasePriority::Urgent, 100);
        $case->forceFill(['status' => ModerationCaseStatus::Actioned, 'resolved_at' => now()])->save();

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertDatabaseMissing('moderation_alerts', ['type' => ModerationAlertType::SlaBreach->value]);
    }

    public function test_a_serial_reporter_raises_a_brigading_alert(): void
    {
        config(['moderation.alerts.brigading.reporter_target_threshold' => 4]);
        $reporter = User::factory()->create();
        $moderation = app(ModerationService::class);

        foreach (Post::factory()->count(4)->create() as $post) {
            $moderation->report($reporter, 'post', $post->id, 'spam', null);
        }

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $alert = ModerationAlert::query()
            ->where('type', ModerationAlertType::Brigading->value)
            ->where('target_id', $reporter->id)
            ->sole();
        $this->assertSame('serial_reporter', $alert->context['shape']);
        $this->assertSame(4, $alert->context['distinct_targets']);
    }

    public function test_a_raid_by_young_accounts_raises_a_critical_brigading_alert(): void
    {
        config([
            'moderation.alerts.brigading.young_reporter_threshold' => 3,
            'moderation.alerts.report_spike.threshold' => 99,
        ]);
        $post = Post::factory()->create();
        $this->reportPost($post, 3);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $alert = ModerationAlert::query()->where('type', ModerationAlertType::Brigading->value)->sole();
        $this->assertSame('young_account_raid', $alert->context['shape']);
        $this->assertSame(ModerationAlertSeverity::Critical, $alert->severity);
    }

    public function test_established_accounts_do_not_count_toward_a_young_account_raid(): void
    {
        config([
            'moderation.alerts.brigading.young_reporter_threshold' => 3,
            'moderation.alerts.brigading.young_account_days' => 7,
            'moderation.alerts.report_spike.threshold' => 99,
        ]);
        $post = Post::factory()->create();
        $moderation = app(ModerationService::class);

        for ($i = 0; $i < 4; $i++) {
            $reporter = User::factory()->create();
            $reporter->forceFill(['created_at' => now()->subMonths(6)])->save();
            $moderation->report($reporter, 'post', $post->id, 'spam', null);
        }

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertDatabaseMissing('moderation_alerts', ['type' => ModerationAlertType::Brigading->value]);
    }

    public function test_a_trending_tag_alerts_as_info_and_escalates_once_reported(): void
    {
        config(['moderation.alerts.trending_tripwire.tag_top_k' => 10]);
        $hashtag = Hashtag::factory()->create(['name' => 'raid']);
        $post = Post::factory()->create();
        $post->hashtags()->attach($hashtag);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $alert = ModerationAlert::query()->where('type', ModerationAlertType::TrendingTripwire->value)->sole();
        $this->assertSame(ModerationAlertSeverity::Info, $alert->severity);
        $this->assertSame('hashtag', $alert->context['surface']);
        $this->assertSame('raid', $alert->context['hashtag_name']);

        config(['moderation.alerts.report_spike.threshold' => 99]);
        $this->reportPost($post, 1);
        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertSame(ModerationAlertSeverity::Warning, $alert->refresh()->severity);
        $this->assertSame(1, $alert->context['reports']);
    }

    public function test_an_already_moderated_tag_stops_tripping_the_tripwire(): void
    {
        $hashtag = Hashtag::factory()->create();
        Post::factory()->create()->hashtags()->attach($hashtag);
        $hashtag->forceFill(['moderation_state' => HashtagModerationState::Blocked])->save();

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertDatabaseMissing('moderation_alerts', ['type' => ModerationAlertType::TrendingTripwire->value]);
    }

    public function test_quiet_mode_only_alerts_on_ranked_items_that_carry_reports(): void
    {
        config(['moderation.alerts.trending_tripwire.only_when_reported' => true]);
        Post::factory()->create()->hashtags()->attach(Hashtag::factory()->create());

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertDatabaseMissing('moderation_alerts', ['type' => ModerationAlertType::TrendingTripwire->value]);
    }

    public function test_a_ranked_post_alerts_from_the_trending_redis_set(): void
    {
        $post = Post::factory()->create();
        Redis::shouldReceive('zrevrange')->andReturn([(string) $post->id]);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $alert = ModerationAlert::query()
            ->where('type', ModerationAlertType::TrendingTripwire->value)
            ->where('target_type', Post::class)
            ->sole();
        $this->assertSame('post', $alert->context['surface']);
        $this->assertSame(1, $alert->context['rank']);
    }

    public function test_the_post_tripwire_fails_open_when_redis_is_unreachable(): void
    {
        Post::factory()->create();
        Redis::shouldReceive('zrevrange')->andThrow(new RuntimeException('Connection refused'));

        // The whole run must still succeed — losing Redis costs one detector half, not the
        // ability to alert on report spikes and SLA breaches.
        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertDatabaseMissing('moderation_alerts', ['target_type' => Post::class, 'type' => ModerationAlertType::TrendingTripwire->value]);
    }

    public function test_one_failing_detector_does_not_abort_the_rest_of_the_run(): void
    {
        config([
            'moderation.alerts.detectors' => [
                ExplodingDetector::class,
                ReportSpikeDetector::class,
            ],
            'moderation.alerts.report_spike.threshold' => 3,
        ]);
        $this->reportPost(Post::factory()->create(), 3);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertDatabaseHas('moderation_alerts', ['type' => ModerationAlertType::ReportSpike->value]);
    }

    public function test_detection_can_be_disabled_wholesale(): void
    {
        config(['moderation.alerts.enabled' => false, 'moderation.alerts.report_spike.threshold' => 1]);
        $this->reportPost(Post::factory()->create(), 2);

        $this->artisan('moderation:detect-alerts')->assertSuccessful();

        $this->assertDatabaseCount('moderation_alerts', 0);
    }

    public function test_alerts_page_requires_view_moderation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('moderation.alerts.index'))->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::ReadOnlyAuditor]));
        $this->get(route('moderation.alerts.index'))->assertOk();
    }

    public function test_an_auditor_can_see_alerts_but_cannot_clear_them(): void
    {
        $alert = $this->openAlert();
        $auditor = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::ReadOnlyAuditor]);

        Livewire::actingAs($auditor)
            ->test(ModerationAlertsTable::class)
            ->call('acknowledge', $alert->id)
            ->assertForbidden();

        $this->assertSame(ModerationAlertState::Open, $alert->fresh()?->state);
    }

    public function test_a_moderator_acknowledges_then_resolves_with_a_reason(): void
    {
        $alert = $this->openAlert();
        $moderator = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);

        $component = Livewire::actingAs($moderator)
            ->test(ModerationAlertsTable::class)
            ->call('acknowledge', $alert->id);

        $this->assertSame(ModerationAlertState::Acknowledged, $alert->fresh()?->state);
        $this->assertSame($moderator->id, $alert->fresh()?->acknowledged_by);

        $component->call('startResolve', $alert->id)
            ->set('resolveReason', 'Checked the tag, it is a football hashtag.')
            ->call('resolve');

        $this->assertSame(ModerationAlertState::Resolved, $alert->fresh()?->state);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'moderation_alert.resolved', 'actor_id' => $moderator->id]);
    }

    public function test_resolving_without_a_reason_is_rejected(): void
    {
        $alert = $this->openAlert();

        Livewire::actingAs(User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]))
            ->test(ModerationAlertsTable::class)
            ->call('startResolve', $alert->id)
            ->set('resolveReason', 'ok')
            ->call('resolve')
            ->assertHasErrors('reason');

        $this->assertSame(ModerationAlertState::Open, $alert->fresh()?->state);
    }

    public function test_the_sidebar_badge_counts_only_unacknowledged_alerts(): void
    {
        $alerts = app(ModerationAlertService::class);
        $alert = $this->openAlert();
        $this->assertSame(1, $alerts->openCount());

        $alerts->acknowledge($alert, User::factory()->create(['is_admin' => true]));

        $this->assertSame(0, $alerts->openCount());
    }

    private function caseAged(ModerationCasePriority $priority, int $ageHours): ModerationCase
    {
        $post = Post::factory()->create();
        $case = ModerationCase::query()->create([
            'target_type' => Post::class,
            'target_id' => $post->id,
            'open_key' => hash('sha256', Post::class.':'.$post->id),
            'status' => ModerationCaseStatus::Open,
            'priority' => $priority,
        ]);
        $case->forceFill(['created_at' => now()->subHours($ageHours)])->save();

        return $case;
    }

    private function openAlert(): ModerationAlert
    {
        return app(ModerationAlertService::class)->raise(new AlertDraft(
            type: ModerationAlertType::ReportSpike,
            severity: ModerationAlertSeverity::Warning,
            title: 'Test alert',
            dedupeKey: 'test:1',
        ));
    }
}

/** Stands in for a detector whose query breaks — see the isolation test above. */
class ExplodingDetector implements AlertDetector
{
    public function name(): string
    {
        return 'exploding';
    }

    public function detect(): iterable
    {
        throw new RuntimeException('detector is broken');
    }
}
