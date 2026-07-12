<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Auth\TwoFactorRequired;
use App\Services\SocialAuth\SocialUserPayload;
use App\Services\SocialAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorLoginChallengeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TOTP codes are deterministic per 30-second window — reusing the exact same code
     * twice within a test (e.g. once to confirm setup, again to complete a login
     * challenge) hits Fortify's own replay-protection cache and is correctly rejected
     * as a replay, not a bug. `$window` picks a distinct-but-still-in-tolerance code:
     * 0 for the confirm step, 1 for a test's own subsequent code — every test here
     * needs at most two distinct real codes.
     */
    private function codeAt(string $secret, int $window): string
    {
        $google2fa = new Google2FA;

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $window);
    }

    private function makeTwoFactorUser(string $password = 'password123!'): User
    {
        $user = User::factory()->create(['password' => $password]);

        app(EnableTwoFactorAuthentication::class)($user);
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $this->codeAt($secret, 0));

        return $user->fresh();
    }

    public function test_login_with_2fa_enabled_returns_a_challenge_instead_of_a_token(): void
    {
        $user = $this->makeTwoFactorUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'password123!',
        ]);

        $response->assertOk();
        $response->assertJsonPath('requires_two_factor', true);
        $response->assertJsonStructure(['two_factor_token']);
        $response->assertJsonMissing(['token']);
    }

    public function test_completing_the_challenge_with_the_correct_code_issues_a_token(): void
    {
        $user = $this->makeTwoFactorUser();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'password123!',
        ]);
        $challengeToken = $login->json('two_factor_token');

        $response = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'code' => $this->codeAt($secret, 1),
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['user' => ['id'], 'token', 'profile_completion']);
        $this->assertSame($user->id, $response->json('user.id'));
    }

    public function test_completing_the_challenge_with_the_wrong_code_fails_and_the_token_stays_usable(): void
    {
        $user = $this->makeTwoFactorUser();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'password123!',
        ]);
        $challengeToken = $login->json('two_factor_token');

        $wrong = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'code' => '000000',
        ]);
        $wrong->assertUnprocessable();

        // The client is allowed to retry with the same challenge token.
        $retry = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'code' => $this->codeAt($secret, 1),
        ]);
        $retry->assertOk();
    }

    public function test_a_challenge_token_cannot_be_reused_after_a_successful_completion(): void
    {
        $user = $this->makeTwoFactorUser();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'password123!',
        ]);
        $challengeToken = $login->json('two_factor_token');
        $code = $this->codeAt($secret, 1);

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'code' => $code,
        ])->assertOk();

        $reuse = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'code' => $code,
        ]);

        $reuse->assertUnprocessable();
        $reuse->assertJsonValidationErrors(['two_factor_token']);
    }

    public function test_an_unknown_challenge_token_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => 'not-a-real-token',
            'code' => '123456',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['two_factor_token']);
    }

    public function test_completing_the_challenge_with_a_recovery_code_consumes_it(): void
    {
        $user = $this->makeTwoFactorUser();
        $recoveryCode = $user->recoveryCodes()[0];

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'password123!',
        ]);
        $challengeToken = $login->json('two_factor_token');

        $response = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'recovery_code' => $recoveryCode,
        ]);

        $response->assertOk();
        $this->assertNotContains($recoveryCode, $user->fresh()->recoveryCodes());
    }

    public function test_completing_the_challenge_with_an_already_used_recovery_code_fails(): void
    {
        $user = $this->makeTwoFactorUser();
        $recoveryCode = $user->recoveryCodes()[0];

        $firstLogin = $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'password123!',
        ]);
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $firstLogin->json('two_factor_token'),
            'recovery_code' => $recoveryCode,
        ])->assertOk();

        $secondLogin = $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'password123!',
        ]);

        $response = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $secondLogin->json('two_factor_token'),
            'recovery_code' => $recoveryCode,
        ]);

        $response->assertUnprocessable();
    }

    /**
     * Simulates the concurrent case directly (a single-threaded test can't produce a
     * true race): another process holding the per-challenge-token lock when this
     * request arrives is exactly what a real concurrent double-submit would look like.
     * See CompleteTwoFactorLogin's doc comment.
     */
    public function test_completing_the_challenge_while_another_request_holds_its_lock_is_rejected(): void
    {
        $user = $this->makeTwoFactorUser();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'password123!',
        ]);
        $challengeToken = $login->json('two_factor_token');

        $lock = Cache::lock("two-factor-challenge:{$challengeToken}:lock", 10);
        $lock->get();

        try {
            $response = $this->postJson('/api/v1/auth/two-factor-challenge', [
                'two_factor_token' => $challengeToken,
                'code' => $this->codeAt($secret, 1),
            ]);

            $response->assertUnprocessable();
            $response->assertJsonValidationErrors(['two_factor_token']);
        } finally {
            $lock->release();
        }

        // The lock being released means the challenge is still completable afterward —
        // this was a "try again" rejection, not a burned/expired token.
        $retry = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'code' => $this->codeAt($secret, 1),
        ]);
        $retry->assertOk();
    }

    /** Same simulated-concurrency approach as above, at the recovery-code-specific lock. */
    public function test_consuming_a_recovery_code_while_another_request_holds_its_lock_is_rejected(): void
    {
        $user = $this->makeTwoFactorUser();
        $recoveryCode = $user->recoveryCodes()[0];

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'password123!',
        ]);
        $challengeToken = $login->json('two_factor_token');

        $lock = Cache::lock("recovery-code-consume:{$user->id}", 10);
        $lock->get();

        try {
            $response = $this->postJson('/api/v1/auth/two-factor-challenge', [
                'two_factor_token' => $challengeToken,
                'recovery_code' => $recoveryCode,
            ]);

            $response->assertUnprocessable();
        } finally {
            $lock->release();
        }

        $this->assertContains($recoveryCode, $user->fresh()->recoveryCodes());
    }

    public function test_social_login_also_gates_on_two_factor(): void
    {
        $user = $this->makeTwoFactorUser();

        $payload = new SocialUserPayload(
            provider: 'google',
            providerUserId: 'google-user-1',
            email: $user->email,
            emailVerified: true,
            name: $user->name,
            avatarUrl: null,
        );

        // Verified-email match links this provider to the existing 2FA-enabled user —
        // exercised at the service level (not a full HTTP round trip through Google's
        // real JWKS) since the point here is the 2FA gate, not token verification
        // (already covered by SocialAuthTest).
        $result = app(SocialAuthService::class)->loginOrRegister($payload, 'mobile');

        $this->assertInstanceOf(TwoFactorRequired::class, $result);
    }
}
