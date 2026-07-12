<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorApiTest extends TestCase
{
    use RefreshDatabase;

    private function withHeaderFor(User $user): self
    {
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function currentCodeFor(User $user): string
    {
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);

        return (new Google2FA)->getCurrentOtp($secret);
    }

    public function test_status_reflects_disabled_by_default(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);

        $response = $this->withHeaderFor($user)->getJson('/api/v1/two-factor');

        $response->assertOk();
        $response->assertJsonPath('enabled', false);
    }

    public function test_enabling_returns_a_qr_code_and_recovery_codes_but_is_not_yet_enabled(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/two-factor', ['current_password' => 'password123!']);

        $response->assertOk();
        $response->assertJsonStructure(['qr_code_svg', 'qr_code_url', 'recovery_codes']);
        $this->assertCount(8, $response->json('recovery_codes'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_enabling_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/two-factor', ['current_password' => 'wrong']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['current_password']);
    }

    public function test_enabling_is_blocked_once_already_enabled(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $this->withHeaderFor($user)->postJson('/api/v1/two-factor', ['current_password' => 'password123!']);
        $this->withHeaderFor($user)->postJson('/api/v1/two-factor/confirm', ['code' => $this->currentCodeFor($user)]);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/two-factor', ['current_password' => 'password123!']);

        $response->assertUnprocessable();
    }

    public function test_confirming_with_the_correct_code_enables_it(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $this->withHeaderFor($user)->postJson('/api/v1/two-factor', ['current_password' => 'password123!']);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/two-factor/confirm', ['code' => $this->currentCodeFor($user)]);

        $response->assertOk();
        $response->assertJsonPath('enabled', true);
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirming_with_the_wrong_code_fails(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $this->withHeaderFor($user)->postJson('/api/v1/two-factor', ['current_password' => 'password123!']);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/two-factor/confirm', ['code' => '000000']);

        $response->assertUnprocessable();
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_disabling_requires_the_current_password_and_clears_state(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $this->withHeaderFor($user)->postJson('/api/v1/two-factor', ['current_password' => 'password123!']);
        $this->withHeaderFor($user)->postJson('/api/v1/two-factor/confirm', ['code' => $this->currentCodeFor($user)]);

        $wrongPassword = $this->withHeaderFor($user)
            ->deleteJson('/api/v1/two-factor', ['current_password' => 'wrong']);
        $wrongPassword->assertUnprocessable();

        $response = $this->withHeaderFor($user)
            ->deleteJson('/api/v1/two-factor', ['current_password' => 'password123!']);

        $response->assertNoContent();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasEnabledTwoFactorAuthentication());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
    }

    public function test_regenerating_recovery_codes_replaces_the_previous_set(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $enable = $this->withHeaderFor($user)->postJson('/api/v1/two-factor', ['current_password' => 'password123!']);
        $this->withHeaderFor($user)->postJson('/api/v1/two-factor/confirm', ['code' => $this->currentCodeFor($user)]);
        $originalCodes = $enable->json('recovery_codes');

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/two-factor/recovery-codes', ['current_password' => 'password123!']);

        $response->assertOk();
        $newCodes = $response->json('recovery_codes');
        $this->assertCount(8, $newCodes);
        $this->assertEmpty(array_intersect($originalCodes, $newCodes));
    }
}
