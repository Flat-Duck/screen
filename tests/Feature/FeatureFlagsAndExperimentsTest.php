<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\ContentEvent;
use App\Models\Experiment;
use App\Models\ExperimentAssignment;
use App\Models\FeatureFlag;
use App\Models\Post;
use App\Models\User;
use App\Services\FeatureConfigurationService;
use App\Services\FeatureEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeatureFlagsAndExperimentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignments_are_stable_deterministic_and_versioned(): void
    {
        $admin = $this->admin();
        $experiment = $this->experiment($admin);
        $user = User::factory()->create();
        $evaluation = app(FeatureEvaluationService::class);

        $first = $evaluation->assignmentsFor($user);
        $second = $evaluation->assignmentsFor($user);
        $this->assertSame($first, $second);
        $this->assertContains($first['feed_density'], ['control', 'treatment']);
        $this->assertDatabaseCount('experiment_assignments', 1);

        ExperimentAssignment::query()->delete();
        $this->assertSame($first, $evaluation->assignmentsFor($user));

        app(FeatureConfigurationService::class)->configureExperiment($admin, 'feed_density', [
            'variants' => ['control' => 5000, 'treatment' => 5000],
            'is_enabled' => true,
        ], 'Start version two');
        $evaluation->assignmentsFor($user);
        $this->assertDatabaseCount('experiment_assignments', 2);
        $this->assertSame([1, 2], ExperimentAssignment::query()->orderBy('experiment_version')->pluck('experiment_version')->all());
        $this->assertSame(2, $experiment->fresh()->version);
    }

    public function test_time_windows_allocation_rollout_and_kill_switches_are_enforced(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $features = app(FeatureConfigurationService::class);
        $evaluation = app(FeatureEvaluationService::class);
        $features->configureFlag($admin, 'feed.new_header', [
            'is_enabled' => true,
            'rollout_basis_points' => 10000,
            'payload' => ['style' => 'compact'],
        ], 'Enable header');
        $this->assertSame('compact', $evaluation->flagsFor($user)['feed.new_header']['payload']['style']);

        FeatureFlag::query()->where('key', 'feed.new_header')->update(['kill_switch' => true]);
        $this->assertSame([], $evaluation->flagsFor($user));

        $experiment = $this->experiment($admin);
        $this->assertArrayHasKey('feed_density', $evaluation->assignmentsFor($user));
        $experiment->update(['kill_switch' => true]);
        $this->assertArrayNotHasKey('feed_density', $evaluation->assignmentsFor($user));
        $experiment->update(['kill_switch' => false, 'starts_at' => now()->addHour()]);
        $this->assertArrayNotHasKey('feed_density', $evaluation->assignmentsFor($user));
    }

    public function test_protected_behavior_cannot_be_experimented_and_configuration_is_audited(): void
    {
        $admin = $this->admin();
        $configuration = app(FeatureConfigurationService::class);

        try {
            $configuration->configureExperiment($admin, 'privacy.weaker_blocks', [
                'variants' => ['control' => 5000, 'treatment' => 5000],
            ], 'Unsafe test');
            $this->fail('Expected protected experiment to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('cannot be experimented', $exception->getMessage());
        }
        $this->assertDatabaseCount('experiments', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);

        $this->experiment($admin);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'experiment.configured',
            'reason' => 'Test feed layout',
        ]);
    }

    public function test_mobile_configuration_and_feed_include_server_assignments(): void
    {
        $admin = $this->admin();
        $this->experiment($admin);
        app(FeatureConfigurationService::class)->configureFlag($admin, 'feed.new_header', [
            'is_enabled' => true,
            'rollout_basis_points' => 10000,
        ], 'Mobile rollout');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $configuration = $this->getJson('/api/v1/feature-configuration')->assertOk()
            ->assertJsonPath('data.experiment_assignments.feed_density', fn ($variant): bool => in_array($variant, ['control', 'treatment'], true));

        $this->assertSame(1, $configuration->json('data.flags')['feed.new_header']['version']);

        $this->getJson('/api/v1/feed')->assertOk()
            ->assertJsonPath('experiment_assignments.feed_density', fn ($variant): bool => in_array($variant, ['control', 'treatment'], true));
    }

    public function test_analytics_accepts_only_assignments_previously_issued_to_that_user(): void
    {
        $admin = $this->admin();
        $this->experiment($admin);
        $user = User::factory()->create();
        $issued = $this->startUserSession($user);
        $post = Post::factory()->create();
        $assignments = app(FeatureEvaluationService::class)->assignmentsFor($user);
        $event = $this->event($post, $assignments);

        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', ['events' => [$event]])
            ->assertOk();
        $this->assertSame($assignments, ContentEvent::firstOrFail()->experiment_assignments);

        $event = $this->event($post, ['feed_density' => $assignments['feed_density'] === 'control' ? 'treatment' : 'control']);
        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', ['events' => [$event]])
            ->assertUnprocessable()->assertJsonValidationErrors('events.0.experiment_assignments');
    }

    public function test_admin_status_page_is_read_only_and_configuration_command_is_audited(): void
    {
        $admin = $this->admin();
        $this->experiment($admin);
        $this->actingAs($admin)->get(route('experiments.index'))->assertOk()
            ->assertSee('Read-only status')->assertSee('feed_density');
        $this->post('/experiments')->assertMethodNotAllowed();

        $this->artisan('features:configure feed.compact '.$admin->email.' --enable --rollout=25 --reason="Gradual rollout"')
            ->assertSuccessful();
        $this->assertDatabaseHas('feature_flags', ['key' => 'feed.compact', 'rollout_basis_points' => 2500]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'feature_flag.configured', 'reason' => 'Gradual rollout']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::SuperAdmin]);
    }

    private function experiment(User $admin): Experiment
    {
        return app(FeatureConfigurationService::class)->configureExperiment($admin, 'feed_density', [
            'scope' => 'recommendation',
            'is_enabled' => true,
            'allocation_basis_points' => 10000,
            'variants' => ['control' => 5000, 'treatment' => 5000],
            'salt' => 'stable-feed-density-salt',
        ], 'Test feed layout');
    }

    /** @param array<string, string> $assignments @return array<string, mixed> */
    private function event(Post $post, array $assignments): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'impression',
            'post_id' => $post->id,
            'author_id' => $post->user_id,
            'surface' => 'for_you_feed',
            'experiment_assignments' => $assignments,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
