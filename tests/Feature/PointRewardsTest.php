<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\FeatureConfigurationService;
use App\Services\PointRewardService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PointRewardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_reward_settings_are_cached_invalidated_after_admin_update_and_credited_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $user = User::factory()->create();
        $rewards = app(PointRewardService::class);

        $this->assertSame(['enabled' => true, 'points' => 30], $rewards->configuration(PointRewardService::FIRST_POST));

        $this->actingAs($admin)->post(route('registration.update'), [
            'enabled' => false,
            'maturity_days' => 7,
            'reason' => 'Configure first post reward',
            'rewards' => [
                PointRewardService::FIRST_POST => ['enabled' => true, 'points' => 45],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('feature_flags', [
            'key' => 'points.reward.first_post',
            'is_enabled' => true,
        ]);
        $this->assertSame(['enabled' => true, 'points' => 45], $rewards->configuration(PointRewardService::FIRST_POST));

        $first = $rewards->awardOnce($user, PointRewardService::FIRST_POST, 'first-post:'.$user->id);
        $again = $rewards->awardOnce($user, PointRewardService::FIRST_POST, 'first-post:'.$user->id);

        $this->assertInstanceOf(PointTransaction::class, $first);
        $this->assertSame($first->id, $again?->id);
        $this->assertSame(45, (int) $user->fresh()->points_balance);
        $this->assertSame(1, PointTransaction::query()->where('idempotency_key', 'first-post:'.$user->id)->count());
    }

    public function test_disabled_reward_does_not_create_a_ledger_entry(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $user = User::factory()->create();
        app(FeatureConfigurationService::class)->configureFlag($admin, 'points.reward.first_private_save', [
            'name' => 'First private save',
            'scope' => 'product',
            'is_enabled' => false,
            'kill_switch' => true,
            'rollout_basis_points' => 10000,
            'payload' => ['points' => 30],
        ], 'Disable private save points');

        $this->assertNull(app(PointRewardService::class)->awardOnce(
            $user,
            PointRewardService::FIRST_PRIVATE_SAVE,
            'first-private-save:'.$user->id,
        ));
        $this->assertSame(0, (int) $user->fresh()->points_balance);
        $this->assertDatabaseMissing('point_transactions', ['user_id' => $user->id, 'reason' => PointRewardService::FIRST_PRIVATE_SAVE]);
    }

    public function test_registration_dashboard_shows_action_reward_controls(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);

        $this->actingAs($admin)->get(route('registration.index'))
            ->assertOk()
            ->assertSee('Points rewards')
            ->assertSee('First screenshot post')
            ->assertSee('Daily login');
    }

    public function test_verified_registration_and_daily_login_use_their_individual_reward_rules(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $user = User::factory()->create();
        $this->configureReward($admin, PointRewardService::ACCOUNT_REGISTRATION, true, 12);
        $this->configureReward($admin, PointRewardService::DAILY_LOGIN, true, 4);

        event(new Verified($user));
        $this->startUserSession($user);
        $this->startUserSession($user);

        $this->assertSame(16, (int) $user->fresh()->points_balance);
        $this->assertDatabaseCount('point_transactions', 2);
        $this->assertDatabaseHas('point_transactions', ['reason' => PointRewardService::ACCOUNT_REGISTRATION, 'amount' => 12]);
        $this->assertDatabaseHas('point_transactions', ['reason' => PointRewardService::DAILY_LOGIN, 'amount' => 4]);
    }

    private function configureReward(User $admin, string $action, bool $enabled, int $points): void
    {
        app(FeatureConfigurationService::class)->configureFlag($admin, 'points.reward.'.$action, [
            'name' => $action,
            'scope' => 'product',
            'is_enabled' => $enabled,
            'kill_switch' => ! $enabled,
            'rollout_basis_points' => 10000,
            'payload' => ['points' => $points],
        ], 'Test reward configuration');
    }
}
