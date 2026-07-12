<?php

namespace Tests\Feature\Api\V1;

use App\Mail\ChangeEmailVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ChangeEmailApiTest extends TestCase
{
    use RefreshDatabase;

    private function withHeaderFor(User $user): self
    {
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
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

    public function test_requesting_a_change_rejects_the_same_email(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'password123!']);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/account/email', ['current_password' => 'password123!', 'email' => $user->email]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_requesting_a_change_sets_pending_email_and_mails_the_new_address_not_the_old_one(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'password123!']);

        $response = $this->withHeaderFor($user)
            ->postJson('/api/v1/account/email', ['current_password' => 'password123!', 'email' => 'new@example.com']);

        $response->assertOk();
        $response->assertJsonPath('pending_email', 'new@example.com');
        $this->assertSame('new@example.com', $user->fresh()->pending_email);
        $this->assertNotSame('new@example.com', $user->fresh()->email);

        // ChangeEmailVerificationMail implements ShouldQueue, so Mail::to()->send() queues
        // it rather than sending inline — see PendingMail::send()'s ShouldQueue check.
        Mail::assertQueued(ChangeEmailVerificationMail::class, function (ChangeEmailVerificationMail $mail) {
            return $mail->hasTo('new@example.com');
        });
    }

    public function test_visiting_the_signed_link_confirms_the_change(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
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
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
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
