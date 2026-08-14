<?php

namespace Tests\Feature;

use App\Actions\Auth\AwardMaturedInvitePoints;
use App\Enums\AdminRole;
use App\Enums\UserModerationState;
use App\Models\FeatureFlag;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InviteAndPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_points_maturity_job_credits_a_redemption_past_the_default_window(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $invite = UserInvite::create([
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'code_used' => (string) $inviter->invite_code,
            'redeemed_at' => now()->subDays(8),
        ]);

        $matured = app(AwardMaturedInvitePoints::class)();

        $this->assertSame(1, $matured);
        $this->assertSame(50, $inviter->fresh()->points_balance);
        $this->assertDatabaseHas('point_transactions', [
            'user_id' => $inviter->id,
            'amount' => 50,
            'reason' => PointTransaction::REASON_REFERRAL_BONUS,
            'user_invite_id' => $invite->id,
        ]);
        $invite->refresh();
        $this->assertNotNull($invite->points_awarded_at);
        $this->assertSame(50, $invite->points_awarded);
    }

    public function test_points_maturity_job_leaves_a_too_recent_redemption_untouched(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        UserInvite::create([
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'code_used' => (string) $inviter->invite_code,
            'redeemed_at' => now()->subDays(2),
        ]);

        $matured = app(AwardMaturedInvitePoints::class)();

        $this->assertSame(0, $matured);
        $this->assertSame(0, $inviter->fresh()->points_balance);
    }

    public function test_points_maturity_job_skips_a_banned_invitee_and_retries_later(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create(['moderation_state' => UserModerationState::Banned]);
        $invite = UserInvite::create([
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'code_used' => (string) $inviter->invite_code,
            'redeemed_at' => now()->subDays(8),
        ]);

        $matured = app(AwardMaturedInvitePoints::class)();

        $this->assertSame(0, $matured);
        $this->assertSame(0, $inviter->fresh()->points_balance);
        $this->assertNull($invite->fresh()->points_awarded_at);
    }

    public function test_points_maturity_job_skips_a_soft_deleted_invitee(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        UserInvite::create([
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'code_used' => (string) $inviter->invite_code,
            'redeemed_at' => now()->subDays(8),
        ]);
        $invitee->delete();

        $matured = app(AwardMaturedInvitePoints::class)();

        $this->assertSame(0, $matured);
        $this->assertSame(0, $inviter->fresh()->points_balance);
    }

    public function test_points_maturity_job_respects_a_configured_maturity_window(): void
    {
        FeatureFlag::create([
            'key' => 'registration.invite_only',
            'name' => 'Invite-only registration',
            'scope' => 'product',
            'is_enabled' => true,
            'kill_switch' => true,
            'rollout_basis_points' => 10000,
            'payload' => ['points_per_invite' => 10, 'maturity_days' => 1],
        ]);
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        UserInvite::create([
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'code_used' => (string) $inviter->invite_code,
            'redeemed_at' => now()->subDays(2),
        ]);

        $matured = app(AwardMaturedInvitePoints::class)();

        $this->assertSame(1, $matured);
        $this->assertSame(10, $inviter->fresh()->points_balance);
    }

    public function test_get_me_invites_lists_only_the_callers_own_redemptions(): void
    {
        $inviter = User::factory()->create();
        $stranger = User::factory()->create();
        $invitee = User::factory()->create();
        UserInvite::create([
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'code_used' => (string) $inviter->invite_code,
            'redeemed_at' => now()->subDays(10),
        ]);
        UserInvite::create([
            'inviter_user_id' => $stranger->id,
            'invitee_user_id' => User::factory()->create()->id,
            'code_used' => (string) $stranger->invite_code,
            'redeemed_at' => now()->subDays(10),
        ]);

        Sanctum::actingAs($inviter);
        $response = $this->getJson('/api/v1/me/invites');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.invitee.id', $invitee->id);
        $response->assertJsonPath('data.0.points_matured', false);
    }

    public function test_own_invite_code_and_points_balance_are_hidden_from_other_viewers(): void
    {
        $user = User::factory()->create(['username' => 'ada']);
        $viewer = User::factory()->create();

        Sanctum::actingAs($user);
        $this->getJson("/api/v1/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.invite_code', $user->invite_code);

        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/users/{$user->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.invite_code')
            ->assertJsonMissingPath('data.points_balance');
    }

    public function test_admin_can_toggle_invite_only_and_tune_points_via_the_registration_page(): void
    {
        $moderator = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);

        $this->actingAs($moderator)->post(route('registration.update'), [
            'enabled' => true,
            'points_per_invite' => 75,
            'maturity_days' => 3,
            'reason' => 'Closed launch',
        ])->assertRedirect();

        $flag = FeatureFlag::query()->where('key', 'registration.invite_only')->firstOrFail();
        $this->assertTrue($flag->isActive());
        $this->assertSame(75, $flag->payload['points_per_invite']);
        $this->assertSame(3, $flag->payload['maturity_days']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'feature_flag.configured', 'reason' => 'Closed launch']);

        $this->actingAs($moderator)->post(route('registration.update'), [
            'enabled' => false,
            'points_per_invite' => 75,
            'maturity_days' => 3,
            'reason' => 'Opening up',
        ])->assertRedirect();

        $this->assertFalse($flag->fresh()->isActive());
    }

    public function test_non_moderator_cannot_toggle_invite_only(): void
    {
        $support = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Support]);

        $this->actingAs($support)->post(route('registration.update'), [
            'enabled' => true,
            'points_per_invite' => 50,
            'maturity_days' => 7,
            'reason' => 'Should be blocked',
        ])->assertForbidden();
    }
}
