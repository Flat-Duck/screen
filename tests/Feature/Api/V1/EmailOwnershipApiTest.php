<?php

namespace Tests\Feature\Api\V1;

use App\Models\PointTransaction;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailOwnershipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_registration_requires_email_verification_and_sends_the_link(): void
    {
        Notification::fake();
        $this->authenticateDevice();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'username' => 'ada',
            'email' => 'ada@example.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email_verification.required', true)
            ->assertJsonPath('email_verification.email', 'ada@example.com')
            ->assertJsonPath('next_action', 'verify_email');

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user): bool {
            $message = $notification->toMail($user);
            $this->assertNotEmpty($message->actionUrl);
            $this->assertTrue(collect([...$message->introLines, ...$message->outroLines])->contains(fn (string $line): bool => preg_match('/^[0-9]{6}$/', $line) === 1));

            return true;
        });
    }

    public function test_unverified_user_is_limited_to_verification_and_account_routes(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        $client = $this->withHeader('Authorization', "Bearer {$token}");

        $client->getJson('/api/v1/auth/email-verification')
            ->assertOk()
            ->assertJson(['verified' => false, 'email' => $user->email]);

        $client->getJson('/api/v1/feed')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified');

        $client->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_resend_is_generic_and_signed_link_verifies_the_user(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/email-verification/notification')
            ->assertStatus(202);

        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user): bool {
            $this->get($notification->toMail($user)->actionUrl)
                ->assertOk()
                ->assertSee('Email verified');

            return true;
        });

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_six_digit_email_code_verifies_the_user_and_is_single_use(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        $code = null;

        $client = $this->withHeader('Authorization', "Bearer {$token}");
        $client->postJson('/api/v1/auth/email-verification/notification')->assertStatus(202);

        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user, &$code): bool {
            $message = $notification->toMail($user);
            foreach ([...$message->introLines, ...$message->outroLines] as $line) {
                if (preg_match('/^([0-9]{6})$/', $line, $matches) === 1) {
                    $code = $matches[1];
                    break;
                }
            }

            return $code !== null;
        });

        $this->assertNotNull($code);
        $client->postJson('/api/v1/auth/email-verification/code', ['code' => $code])
            ->assertOk()
            ->assertJson(['verified' => true]);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $client->postJson('/api/v1/auth/email-verification/code', ['code' => '000000'])
            ->assertOk()
            ->assertJson(['verified' => true]);
    }

    public function test_verifying_an_invited_registration_credits_the_invitee_once_and_returns_the_inviter(): void
    {
        Notification::fake();
        $inviter = User::factory()->create(['name' => 'Inviting Friend', 'username' => 'friend']);
        $this->authenticateDevice();

        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'username' => 'ada',
            'email' => 'ada@example.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
            'invite_code' => $inviter->invite_code,
        ])->assertCreated();

        $invitee = User::query()->where('email', 'ada@example.com')->firstOrFail();
        Sanctum::actingAs($invitee);
        $client = $this;
        $client->postJson('/api/v1/auth/email-verification/notification')->assertStatus(202);
        $code = null;
        Notification::assertSentTo($invitee, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($invitee, &$code): bool {
            foreach ([...$notification->toMail($invitee)->introLines, ...$notification->toMail($invitee)->outroLines] as $line) {
                if (preg_match('/^([0-9]{6})$/', $line, $matches) === 1) {
                    $code = $matches[1];
                    break;
                }
            }

            return $code !== null;
        });

        $client->postJson('/api/v1/auth/email-verification/code', ['code' => $code])
            ->assertOk()->assertJson(['verified' => true]);
        $client->getJson('/api/v1/auth/email-verification')
            ->assertOk()
            ->assertJsonPath('invitation_reward.credited', true)
            ->assertJsonPath('invitation_reward.points', 50)
            ->assertJsonPath('invitation_reward.inviter.name', 'Inviting Friend')
            ->assertJsonPath('invitation_reward.inviter.username', 'friend');

        $client->getJson('/api/v1/auth/email-verification');
        $this->assertSame(50, $invitee->fresh()->points_balance);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertDatabaseHas('point_transactions', [
            'user_id' => $invitee->id,
            'reason' => PointTransaction::REASON_INVITEE_WELCOME_BONUS,
            'amount' => 50,
        ]);
    }

    public function test_invalid_verification_codes_are_rejected_and_limited_to_five_attempts(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        $client = $this->withHeader('Authorization', "Bearer {$token}");
        $client->postJson('/api/v1/auth/email-verification/notification')->assertStatus(202);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $client->postJson('/api/v1/auth/email-verification/code', ['code' => '000000'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('code');
        }

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_code_requires_exactly_six_ascii_digits(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/email-verification/code', ['code' => '12ab56'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_expired_verification_code_is_rejected(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        $client = $this->withHeader('Authorization', "Bearer {$token}");
        $client->postJson('/api/v1/auth/email-verification/notification')->assertStatus(202);

        $this->travel(11)->minutes();

        $client->postJson('/api/v1/auth/email-verification/code', ['code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_forgot_password_response_does_not_enumerate_accounts_or_social_only_users(): void
    {
        Notification::fake();
        $passwordUser = User::factory()->create(['email' => 'password@example.com']);
        $socialUser = User::factory()->create(['email' => 'social@example.com', 'password' => null]);
        $this->authenticateDevice();

        foreach (['password@example.com', 'social@example.com', 'missing@example.com'] as $email) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])
                ->assertStatus(202)
                ->assertJsonStructure(['message']);
        }

        Notification::assertSentTo($passwordUser, ResetPasswordNotification::class);
        Notification::assertNotSentTo($socialUser, ResetPasswordNotification::class);
    }

    public function test_valid_mobile_reset_proves_email_ownership_and_revokes_existing_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create([
            'email' => 'owner@example.com',
            'password' => 'OldPassword1!',
        ]);
        $user->createToken('stolen-session');
        $this->authenticateDevice();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $response = $this->postJson('/api/v1/auth/reset-password', [
                'email' => $user->email,
                'token' => $notification->token,
                'password' => 'NewPassword1!',
                'password_confirmation' => 'NewPassword1!',
            ]);

            $response->assertOk();

            return true;
        });

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasVerifiedEmail());
        $this->assertTrue(Hash::check('NewPassword1!', $fresh->password));
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_invalid_reset_token_is_rejected_without_changing_the_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword1!']);
        $this->authenticateDevice();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('OldPassword1!', $user->fresh()->password));
    }
}
