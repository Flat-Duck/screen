<?php

namespace Tests\Feature\Api\V1;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectedAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private function withHeaderFor(User $user): self
    {
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
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

    public function test_unlinking_a_provider_removes_it_when_a_password_is_set(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        SocialAccount::factory()->for($user)->create(['provider' => SocialAccount::PROVIDER_GOOGLE]);

        $this->withHeaderFor($user)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_GOOGLE)
            ->assertNoContent();

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_unlinking_the_only_provider_on_a_passwordless_account_is_refused(): void
    {
        $user = User::factory()->create(['password' => null]);
        SocialAccount::factory()->for($user)->create(['provider' => SocialAccount::PROVIDER_GOOGLE]);

        $response = $this->withHeaderFor($user)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_GOOGLE);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['provider']);
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_unlinking_one_of_several_providers_on_a_passwordless_account_is_allowed(): void
    {
        $user = User::factory()->create(['password' => null]);
        SocialAccount::factory()->for($user)->create(['provider' => SocialAccount::PROVIDER_GOOGLE]);
        SocialAccount::factory()->for($user)->create(['provider' => SocialAccount::PROVIDER_FACEBOOK]);

        $this->withHeaderFor($user)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_GOOGLE)
            ->assertNoContent();

        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_unlinking_an_unlinked_provider_is_a_silent_no_op(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);

        $this->withHeaderFor($user)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_APPLE)
            ->assertNoContent();
    }

    public function test_unlinking_never_touches_another_users_provider(): void
    {
        $owner = User::factory()->create(['password' => null]);
        SocialAccount::factory()->for($owner)->create(['provider' => SocialAccount::PROVIDER_GOOGLE]);

        $attacker = User::factory()->create();

        $this->withHeaderFor($attacker)
            ->deleteJson('/api/v1/connected-accounts/'.SocialAccount::PROVIDER_GOOGLE)
            ->assertNoContent();

        $this->assertDatabaseCount('social_accounts', 1);
    }
}
