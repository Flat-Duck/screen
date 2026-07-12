<?php

namespace Tests\Feature\Api\V1;

use App\Mail\AccountConfirmationCodeMail;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConnectedAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private function withHeaderFor(User $user): self
    {
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** For a passwordless, non-2FA account — the only step-up proof it has available. */
    private function confirmationCodeFor(User $user): string
    {
        Mail::fake();

        $this->withHeaderFor($user)->postJson('/api/v1/account/confirmation-code')->assertNoContent();

        $code = null;
        Mail::assertQueued(AccountConfirmationCodeMail::class, function (AccountConfirmationCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    public function test_listing_returns_only_the_current_users_linked_providers(): void
    {
        $user = User::factory()->create();
        SocialAccount::factory()->for($user)->create(['provider' => SocialAccount::PROVIDER_GOOGLE]);

        $stranger = User::factory()->create();
        SocialAccount::factory()->for($stranger)->create(['provider' => SocialAccount::PROVIDER_APPLE]);

        $response = $this->withHeaderFor($user)->getJson('/api/v1/connected-accounts');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.provider', SocialAccount::PROVIDER_GOOGLE);
    }

    public function test_unlinking_requires_the_current_password_when_one_is_set(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        SocialAccount::factory()->for($user)->create(['provider' => SocialAccount::PROVIDER_GOOGLE]);

        $wrongPassword = $this->withHeaderFor($user)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_GOOGLE, ['current_password' => 'wrong']);
        $wrongPassword->assertUnprocessable();
        $wrongPassword->assertJsonValidationErrors(['current_password']);
        $this->assertDatabaseCount('social_accounts', 1);

        $this->withHeaderFor($user)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_GOOGLE, ['current_password' => 'password123!'])
            ->assertNoContent();

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_unlinking_the_only_provider_on_a_passwordless_account_is_refused(): void
    {
        $user = User::factory()->create(['password' => null]);
        SocialAccount::factory()->for($user)->create(['provider' => SocialAccount::PROVIDER_GOOGLE]);
        $code = $this->confirmationCodeFor($user);

        $response = $this->withHeaderFor($user)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_GOOGLE, ['confirmation_code' => $code]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['provider']);
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_unlinking_one_of_several_providers_on_a_passwordless_account_is_allowed(): void
    {
        $user = User::factory()->create(['password' => null]);
        SocialAccount::factory()->for($user)->create(['provider' => SocialAccount::PROVIDER_GOOGLE]);
        SocialAccount::factory()->for($user)->create(['provider' => SocialAccount::PROVIDER_FACEBOOK]);
        $code = $this->confirmationCodeFor($user);

        $this->withHeaderFor($user)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_GOOGLE, ['confirmation_code' => $code])
            ->assertNoContent();

        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_unlinking_an_unlinked_provider_is_a_silent_no_op(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);

        $this->withHeaderFor($user)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_APPLE, ['current_password' => 'password123!'])
            ->assertNoContent();
    }

    public function test_unlinking_never_touches_another_users_provider(): void
    {
        $owner = User::factory()->create(['password' => null]);
        SocialAccount::factory()->for($owner)->create(['provider' => SocialAccount::PROVIDER_GOOGLE]);

        $attacker = User::factory()->create(['password' => 'password123!']);

        $this->withHeaderFor($attacker)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_GOOGLE, ['current_password' => 'password123!'])
            ->assertNoContent();

        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_sending_a_confirmation_code_is_refused_for_an_account_with_a_password(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);

        $response = $this->withHeaderFor($user)->postJson('/api/v1/account/confirmation-code');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['confirmation_code']);
    }
}
