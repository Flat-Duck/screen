<?php

namespace Tests\Feature\Api\V1;

use App\Enums\SecurityOutboxType;
use App\Mail\AccountConfirmationCodeMail;
use App\Models\User;
use App\Services\EmailChangeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class ChangeEmailApiTest extends TestCase
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

    public function test_requesting_a_change_requires_the_current_password(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'password123!']);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/account/email', ['current_password' => 'wrong', 'email' => 'new@example.com']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['current_password']);
        $this->assertNull($user->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    public function test_requesting_a_change_rejects_an_email_already_taken(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'password123!']);
        $taken = User::factory()->create()->email;

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/account/email', ['current_password' => 'password123!', 'email' => $taken]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_requesting_a_change_rejects_an_email_already_pending_for_another_user(): void
    {
        Mail::fake();
        $other = User::factory()->create();
        $other->pending_email = 'contested@example.com';
        $other->save();

        $user = User::factory()->create(['password' => 'password123!']);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/account/email', ['current_password' => 'password123!', 'email' => 'contested@example.com']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_a_pending_email_collision_that_slips_past_validation_still_fails_cleanly(): void
    {
        Mail::fake();
        $other = User::factory()->create();
        $other->pending_email = 'contested@example.com';
        $other->save();

        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(EmailChangeService::class)->requestChange($user, 'contested@example.com');
    }

    /**
     * The test suite runs on SQLite (see phpunit.xml), which — like MySQL — reports a
     * unique violation as SQLSTATE '23000'. This app's real driver is Postgres (see
     * config/database.php), which reports the more specific '23505' instead — a code
     * SQLite/MySQL-backed tests alone would never exercise. Calling the private
     * recognizer directly is the only way to prove the Postgres code path is actually
     * handled without standing up a real Postgres test database.
     */
    public function test_recognizes_postgres_unique_violation_sqlstate_not_just_mysqls(): void
    {
        $method = new ReflectionMethod(EmailChangeService::class, 'isUniqueConstraintViolation');
        $method->setAccessible(true);

        $previous = new \PDOException('duplicate key value violates unique constraint "users_email_unique"', 23505);
        $postgresViolation = new QueryException('pgsql', 'select 1', [], $previous);

        $this->assertTrue($method->invoke(app(EmailChangeService::class), $postgresViolation));
    }

    /**
     * The step-up `after()` hook must not spend a single-use email code when some
     * *other* field on the same request is invalid — the request as a whole still
     * fails either way, but burning the code too would force the caller to request a
     * fresh one just to retry with a corrected field.
     */
    public function test_an_invalid_email_does_not_consume_the_step_up_code(): void
    {
        $user = User::factory()->create(['password' => null]);
        $code = $this->confirmationCodeFor($user);

        $invalidEmail = $this->withHeaderFor($user)
            ->postJson('/api/v1/account/email', ['confirmation_code' => $code, 'email' => 'not-an-email']);
        $invalidEmail->assertUnprocessable();
        $invalidEmail->assertJsonValidationErrors(['email']);
        $invalidEmail->assertJsonMissingValidationErrors(['confirmation_code']);

        $retry = $this->withHeaderFor($user)
            ->postJson('/api/v1/account/email', ['confirmation_code' => $code, 'email' => 'new@example.com']);
        $retry->assertOk();
    }

    public function test_requesting_a_change_rejects_the_same_email(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'password123!']);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/account/email', ['current_password' => 'password123!', 'email' => $user->email]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_requesting_a_change_sets_pending_email_and_enqueues_the_new_address_notification(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'password123!']);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/account/email', ['current_password' => 'password123!', 'email' => 'new@example.com']);

        $response->assertOk();
        $response->assertJsonPath('pending_email', 'new@example.com');
        $this->assertSame('new@example.com', $user->fresh()->pending_email);
        $this->assertNotSame('new@example.com', $user->fresh()->email);

        $this->assertDatabaseHas('security_outbox_messages', [
            'type' => SecurityOutboxType::ChangeEmailVerification->value,
            'recipient' => 'new@example.com',
        ]);
    }

    public function test_visiting_the_signed_link_confirms_the_change(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'password123!']);
        $originalEmail = $user->email;
        $user->pending_email = 'new@example.com';
        $user->save();

        $url = URL::temporarySignedRoute(
            'email.change.verify',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1('new@example.com')],
        );

        $response = $this->get($url);

        $response->assertOk();
        $fresh = $user->fresh();
        $this->assertSame('new@example.com', $fresh->email);
        $this->assertNull($fresh->pending_email);
        $this->assertNotNull($fresh->email_verified_at);

        $this->assertDatabaseHas('security_outbox_messages', [
            'type' => SecurityOutboxType::EmailChangedNotification->value,
            'recipient' => $originalEmail,
        ]);
    }

    public function test_confirming_revokes_every_session_including_the_one_that_requested_the_change(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $user->pending_email = 'new@example.com';
        $user->save();
        $user->createToken('device-a');
        $user->createToken('device-b');

        $url = URL::temporarySignedRoute(
            'email.change.verify',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1('new@example.com')],
        );

        $this->get($url)->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $user->pending_email = 'new@example.com';
        $user->save();

        $url = URL::temporarySignedRoute(
            'email.change.verify',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1('new@example.com')],
        );

        $this->get($url.'-tampered')->assertForbidden();
        $this->assertSame('new@example.com', $user->fresh()->pending_email);
    }

    public function test_a_stale_link_for_a_superseded_pending_email_is_rejected(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $user->pending_email = 'first@example.com';
        $user->save();

        $staleUrl = URL::temporarySignedRoute(
            'email.change.verify',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1('first@example.com')],
        );

        // A second change request supersedes the first before the first link is ever clicked.
        $user->pending_email = 'second@example.com';
        $user->save();

        $response = $this->get($staleUrl);

        $response->assertOk();
        $fresh = $user->fresh();
        $this->assertSame('second@example.com', $fresh->pending_email);
        $this->assertNotSame('first@example.com', $fresh->email);
    }
}
