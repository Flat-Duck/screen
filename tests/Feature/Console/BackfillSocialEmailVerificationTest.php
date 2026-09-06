<?php

namespace Tests\Feature\Console;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillSocialEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function socialUser(string $provider, string $email, ?string $verifiedAt = null): User
    {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => $verifiedAt]);
        SocialAccount::factory()->create(['user_id' => $user->id, 'provider' => $provider]);

        return $user;
    }

    public function test_it_verifies_accounts_the_provider_had_already_confirmed(): void
    {
        // Meta only ever hands back addresses it has confirmed, and a gmail.com mailbox is
        // operated by Google itself — in both cases the provider vouched for the address.
        $facebook = $this->socialUser('facebook', 'someone@anything.example');
        $gmail = $this->socialUser('google', 'someone@gmail.com');

        $this->artisan('users:backfill-social-verification')->assertSuccessful();

        $this->assertNotNull($facebook->fresh()?->email_verified_at);
        $this->assertNotNull($gmail->fresh()?->email_verified_at);
    }

    public function test_it_leaves_google_accounts_on_a_custom_domain_alone(): void
    {
        // GoogleTokenVerifier reads `email_verified` off the token and it can be false for an
        // unverified Workspace domain. Nothing recorded what it said, so this stays unverified.
        $workspace = $this->socialUser('google', 'someone@acme-corp.example');

        $this->artisan('users:backfill-social-verification')->assertSuccessful();

        $this->assertNull($workspace->fresh()?->email_verified_at);
    }

    public function test_it_does_not_touch_password_accounts_or_move_an_existing_timestamp(): void
    {
        $passwordOnly = User::factory()->create(['email_verified_at' => null]);
        $alreadyVerified = $this->socialUser('google', 'early@gmail.com', '2026-01-01 00:00:00');

        $this->artisan('users:backfill-social-verification')->assertSuccessful();

        $this->assertNull($passwordOnly->fresh()?->email_verified_at);
        $this->assertSame(
            '2026-01-01 00:00:00',
            $alreadyVerified->fresh()?->email_verified_at?->toDateTimeString(),
        );
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $gmail = $this->socialUser('google', 'someone@gmail.com');

        $this->artisan('users:backfill-social-verification', ['--dry-run' => true])
            ->expectsOutputToContain('1 account(s) would be marked verified')
            ->assertSuccessful();

        $this->assertNull($gmail->fresh()?->email_verified_at);
    }

    public function test_it_is_idempotent(): void
    {
        $gmail = $this->socialUser('google', 'someone@gmail.com');

        $this->artisan('users:backfill-social-verification')->assertSuccessful();
        $firstRun = $gmail->fresh()?->email_verified_at;

        $this->artisan('users:backfill-social-verification')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertSuccessful();

        $this->assertEquals($firstRun, $gmail->fresh()?->email_verified_at);
    }
}
