<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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
        Notification::assertSentTo($user, VerifyEmailNotification::class);
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
