<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'username' => 'ada',
            'email' => 'ada@example.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
        ], $overrides);
    }

    public function test_registering_creates_a_user_and_returns_a_token(): void
    {
        $this->authenticateDevice();
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertCreated();
        $response->assertJsonStructure(['user' => ['id', 'username'], 'token', 'session_id']);
        $this->assertDatabaseHas('device_sessions', ['login_method' => 'registration']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['username' => 'ada']);
    }

    public function test_registering_with_a_duplicate_username_fails_validation(): void
    {
        $this->authenticateDevice();
        User::factory()->create(['username' => 'ada']);

        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['username']);
    }

    public function test_login_with_correct_credentials_returns_a_token(): void
    {
        $device = $this->authenticateDevice();
        $user = User::factory()->create(['username' => 'ada', 'password' => 'password123!']);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'ada',
            'password' => 'password123!',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['user' => ['id'], 'token', 'session_id']);
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertSame($user->id, $device->fresh()->user_id);
        $this->assertDatabaseHas('device_sessions', ['user_id' => $user->id, 'login_method' => 'password']);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $this->authenticateDevice();
        User::factory()->create(['username' => 'ada', 'password' => 'password123!']);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'ada',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['login']);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('device-a')->plainTextToken;
        $user->createToken('device-b');

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/auth/logout');

        $response->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /** Guards against the Sanctum-principal-confusion gotcha: a Device token must not work here. */
    public function test_a_device_token_cannot_access_user_only_routes(): void
    {
        $device = Device::factory()->create();
        Sanctum::actingAs($device);

        $response = $this->getJson('/api/v1/feed');

        $response->assertForbidden();
    }

    public function test_registering_with_a_device_name_names_the_token(): void
    {
        $this->authenticateDevice();
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['device_name' => 'pixel-8']))
            ->assertCreated();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'pixel-8']);
    }

    public function test_registering_without_a_device_name_defaults_the_token_name(): void
    {
        $this->authenticateDevice();
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'mobile']);
    }

    public function test_login_with_a_device_name_names_the_token(): void
    {
        $this->authenticateDevice();
        User::factory()->create(['username' => 'ada', 'password' => 'password123!']);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'ada',
            'password' => 'password123!',
            'device_name' => 'pixel-8',
        ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'pixel-8']);
    }

    public function test_every_new_user_gets_their_own_invite_code(): void
    {
        $this->authenticateDevice();
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $user = User::query()->where('username', 'ada')->firstOrFail();
        $this->assertNotNull($user->invite_code);
        $this->assertSame(0, $user->points_balance);
    }

    public function test_registering_with_a_valid_invite_code_records_a_pending_redemption(): void
    {
        // invite_code is server-generated (not mass-assignable), so read it back rather than
        // forcing a specific value — every factory-created user already has one, per User::booted().
        $inviter = User::factory()->create();
        $this->authenticateDevice();

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['invite_code' => strtolower((string) $inviter->invite_code)]))
            ->assertCreated();

        $invitee = User::query()->where('username', 'ada')->firstOrFail();
        $this->assertDatabaseHas('user_invites', [
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'code_used' => $inviter->invite_code,
            'points_awarded_at' => null,
        ]);
        // Redeeming a code never awards points synchronously — only AwardMaturedInvitePoints does.
        $this->assertSame(0, $inviter->fresh()->points_balance);
    }

    public function test_registering_with_an_unknown_invite_code_is_rejected(): void
    {
        $this->authenticateDevice();

        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload(['invite_code' => 'NOPE']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['invite_code']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registering_without_a_code_is_allowed_when_invite_only_is_off(): void
    {
        $this->authenticateDevice();

        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
    }

    public function test_registering_without_a_code_is_rejected_when_invite_only_is_on(): void
    {
        $this->enableInviteOnly();
        $this->authenticateDevice();

        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['invite_code']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registering_with_a_valid_code_succeeds_when_invite_only_is_on(): void
    {
        $inviter = User::factory()->create();
        $this->enableInviteOnly();
        $this->authenticateDevice();

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['invite_code' => $inviter->invite_code]))
            ->assertCreated();
    }

    private function enableInviteOnly(): void
    {
        FeatureFlag::create([
            'key' => 'registration.invite_only',
            'name' => 'Invite-only registration',
            'scope' => 'product',
            'is_enabled' => true,
            'kill_switch' => false,
            'rollout_basis_points' => 10000,
        ]);
    }
}
