<?php

namespace Tests\Feature\Api\V1;

use App\Models\SocialAccount;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_CLIENT_ID = 'test-google-client-id';

    private const APPLE_CLIENT_ID = 'test-apple-client-id';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => self::GOOGLE_CLIENT_ID,
            'services.apple.client_id' => self::APPLE_CLIENT_ID,
            'services.facebook.app_id' => 'test-fb-app-id',
            'services.facebook.app_secret' => 'test-fb-app-secret',
        ]);
    }

    public function test_google_sign_in_creates_a_new_account_when_none_exists(): void
    {
        $this->fakeGoogleJwks($kid = 'kid-1', $privateKey);

        $idToken = $this->signJwt($privateKey, $kid, [
            'iss' => 'https://accounts.google.com',
            'aud' => self::GOOGLE_CLIENT_ID,
            'sub' => 'google-user-1',
            'email' => 'newuser@example.com',
            'email_verified' => true,
            'name' => 'New User',
            'picture' => 'https://example.com/avatar.jpg',
        ]);

        Http::fake([
            'https://example.com/avatar.jpg' => Http::response('not-a-real-image', 200),
        ]);

        $response = $this->postJson('/api/v1/auth/social/google', ['id_token' => $idToken]);

        $response->assertCreated();
        $response->assertJsonPath('is_new_account', true);
        $response->assertJsonPath('profile_completion.is_complete', false);
        $response->assertJsonPath('profile_completion.has_username', false);
        $response->assertJsonPath('profile_completion.has_password', false);
        $response->assertJsonStructure(['user' => ['id'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'newuser@example.com', 'name' => 'New User']);
        $this->assertDatabaseHas('social_accounts', [
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-user-1',
        ]);

        $user = User::query()->where('email', 'newuser@example.com')->firstOrFail();
        $this->assertNull($user->username);
        $this->assertNull($user->password);
        // The fake "not-a-real-image" body can't be decoded — must not break sign-in.
        $this->assertNull($user->avatar_path);
    }

    public function test_repeat_google_sign_in_logs_into_the_same_user(): void
    {
        $this->fakeGoogleJwks($kid = 'kid-1', $privateKey);

        $claims = [
            'iss' => 'https://accounts.google.com',
            'aud' => self::GOOGLE_CLIENT_ID,
            'sub' => 'google-user-1',
            'email' => 'newuser@example.com',
            'email_verified' => true,
            'name' => 'New User',
        ];

        $first = $this->postJson('/api/v1/auth/social/google', [
            'id_token' => $this->signJwt($privateKey, $kid, $claims),
        ]);
        $first->assertCreated();
        $userId = $first->json('user.id');

        $second = $this->postJson('/api/v1/auth/social/google', [
            'id_token' => $this->signJwt($privateKey, $kid, $claims),
        ]);
        $second->assertOk();
        $second->assertJsonPath('is_new_account', false);
        $second->assertJsonPath('user.id', $userId);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_verified_email_auto_links_to_an_existing_password_account(): void
    {
        $existing = User::factory()->create(['email' => 'ada@example.com']);

        $this->fakeGoogleJwks($kid = 'kid-1', $privateKey);

        $idToken = $this->signJwt($privateKey, $kid, [
            'iss' => 'https://accounts.google.com',
            'aud' => self::GOOGLE_CLIENT_ID,
            'sub' => 'google-user-1',
            'email' => 'ada@example.com',
            'email_verified' => true,
            'name' => 'Ada',
        ]);

        $response = $this->postJson('/api/v1/auth/social/google', ['id_token' => $idToken]);

        $response->assertOk();
        $response->assertJsonPath('is_new_account', false);
        $response->assertJsonPath('user.id', $existing->id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $existing->id, 'provider' => 'google']);
    }

    public function test_unverified_email_does_not_auto_link_and_is_rejected(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->fakeGoogleJwks($kid = 'kid-1', $privateKey);

        $idToken = $this->signJwt($privateKey, $kid, [
            'iss' => 'https://accounts.google.com',
            'aud' => self::GOOGLE_CLIENT_ID,
            'sub' => 'google-user-1',
            'email' => 'ada@example.com',
            'email_verified' => false,
            'name' => 'Ada',
        ]);

        $response = $this->postJson('/api/v1/auth/social/google', ['id_token' => $idToken]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_facebook_sign_in_creates_a_new_account(): void
    {
        Http::fake([
            'https://graph.facebook.com/debug_token*' => Http::response([
                'data' => ['is_valid' => true, 'app_id' => 'test-fb-app-id'],
            ]),
            'https://graph.facebook.com/me*' => Http::response([
                'id' => 'fb-user-1',
                'name' => 'Facebook User',
                'email' => 'fbuser@example.com',
                'picture' => ['data' => ['url' => 'https://example.com/pic.jpg']],
            ]),
            'https://example.com/pic.jpg' => Http::response('not-a-real-image', 200),
        ]);

        $response = $this->postJson('/api/v1/auth/social/facebook', ['access_token' => 'fake-fb-token']);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'fbuser@example.com']);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'facebook', 'provider_user_id' => 'fb-user-1']);
    }

    public function test_facebook_sign_in_rejects_a_token_issued_for_a_different_app(): void
    {
        Http::fake([
            'https://graph.facebook.com/debug_token*' => Http::response([
                'data' => ['is_valid' => true, 'app_id' => 'some-other-app'],
            ]),
        ]);

        $response = $this->postJson('/api/v1/auth/social/facebook', ['access_token' => 'fake-fb-token']);

        $response->assertUnprocessable();
    }

    public function test_apple_sign_in_uses_client_provided_name_since_the_token_never_carries_one(): void
    {
        $this->fakeAppleJwks($kid = 'kid-1', $privateKey);

        $identityToken = $this->signJwt($privateKey, $kid, [
            'iss' => 'https://appleid.apple.com',
            'aud' => self::APPLE_CLIENT_ID,
            'sub' => 'apple-user-1',
            'email' => 'appleuser@example.com',
            'email_verified' => 'true',
        ]);

        $response = $this->postJson('/api/v1/auth/social/apple', [
            'identity_token' => $identityToken,
            'given_name' => 'Grace',
            'family_name' => 'Hopper',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'appleuser@example.com', 'name' => 'Grace Hopper']);
    }

    public function test_login_against_a_social_only_account_without_a_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'social-only', 'email' => 'social@example.com', 'password' => null]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'social-only',
            'password' => 'whatever123!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['login']);
    }

    public function test_setting_username_via_profile_update_completes_the_profile(): void
    {
        $user = User::factory()->create(['username' => null]);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/profile', ['username' => 'newhandle']);

        $response->assertOk();
        $response->assertJsonPath('data.username', 'newhandle');
        $response->assertJsonPath('profile_completion.is_complete', true);
        $this->assertSame('newhandle', $user->fresh()->username);
    }

    public function test_setting_username_rejects_a_taken_one(): void
    {
        User::factory()->create(['username' => 'taken']);
        $user = User::factory()->create(['username' => null]);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/profile', ['username' => 'taken']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['username']);
    }

    public function test_set_password_on_a_social_only_account_requires_no_current_password(): void
    {
        $user = User::factory()->create(['password' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/password', [
            'password' => 'BrandNewPassword1!',
            'password_confirmation' => 'BrandNewPassword1!',
        ]);

        $response->assertOk();
        $response->assertJsonPath('profile_completion.has_password', true);
        $this->assertNotNull($user->fresh()->password);
    }

    public function test_changing_an_existing_password_requires_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword1!']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/password', [
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['current_password']);
    }

    public function test_changing_an_existing_password_succeeds_with_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword1!']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/password', [
            'current_password' => 'OldPassword1!',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertOk();
    }

    /**
     * @param  OpenSSLAsymmetricKey|string|null  $privateKey
     */
    private function fakeGoogleJwks(string $kid, &$privateKey): void
    {
        $jwks = $this->generateRsaJwks($kid, $privateKey);

        Http::fake([
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response($jwks),
        ]);
    }

    /**
     * @param  OpenSSLAsymmetricKey|string|null  $privateKey
     */
    private function fakeAppleJwks(string $kid, &$privateKey): void
    {
        $jwks = $this->generateRsaJwks($kid, $privateKey);

        Http::fake([
            'https://appleid.apple.com/auth/keys' => Http::response($jwks),
        ]);
    }

    /**
     * @param  OpenSSLAsymmetricKey|string|null  $privateKey
     * @return array<string, mixed>
     */
    private function generateRsaJwks(string $kid, &$privateKey): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        return [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => $kid,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $this->base64UrlEncode($details['rsa']['n']),
                'e' => $this->base64UrlEncode($details['rsa']['e']),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function signJwt(string $privateKey, string $kid, array $claims): string
    {
        $claims += ['iat' => time(), 'exp' => time() + 3600];

        return JWT::encode($claims, $privateKey, 'RS256', $kid);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
