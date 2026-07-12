<?php

namespace Tests\Feature\Api\V1;

use App\Mail\AccountConfirmationCodeMail;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteAccountApiTest extends TestCase
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

    public function test_deleting_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $token = $user->createToken('mobile')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account', ['current_password' => 'wrong']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['current_password']);
        $this->assertNotSoftDeleted($user);
    }

    public function test_deleting_a_passwordless_account_requires_the_email_confirmation_code(): void
    {
        $user = User::factory()->create(['password' => null]);

        $withoutCode = $this->withHeaderFor($user)->deleteJson('/api/v1/account');
        $withoutCode->assertUnprocessable();
        $withoutCode->assertJsonValidationErrors(['confirmation_code']);

        $code = $this->confirmationCodeFor($user);

        $this->withHeaderFor($user)
            ->deleteJson('/api/v1/account', ['confirmation_code' => $code])
            ->assertNoContent();

        $this->assertSoftDeleted($user);
    }

    public function test_deleting_a_passwordless_2fa_account_requires_a_totp_code_not_the_email_code(): void
    {
        $user = User::factory()->withTwoFactor()->create(['password' => null]);

        $response = $this->withHeaderFor($user)->postJson('/api/v1/account/confirmation-code');
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['confirmation_code']);
    }

    public function test_deleting_soft_deletes_the_account_revokes_all_tokens_and_hides_it_from_others(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $token = $user->createToken('device-a')->plainTextToken;
        $user->createToken('device-b');

        $post = Post::factory()->for($user)->create();

        $other = User::factory()->create();
        $other->following()->attach($user->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account', ['current_password' => 'password123!'])
            ->assertNoContent();

        $this->assertSoftDeleted($user);
        $this->assertSoftDeleted($post);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $otherToken = $other->createToken('mobile')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson("/api/v1/users/{$user->id}")
            ->assertNotFound();

        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson("/api/v1/users/{$other->id}/following")
            ->assertJsonCount(0, 'data');
    }

    public function test_a_deleted_account_cannot_log_back_in(): void
    {
        $user = User::factory()->create(['password' => 'password123!', 'username' => 'deleteme']);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account', ['current_password' => 'password123!'])
            ->assertNoContent();

        $this->postJson('/api/v1/auth/login', ['login' => 'deleteme', 'password' => 'password123!'])
            ->assertUnprocessable();
    }

    public function test_prune_command_purges_accounts_and_their_files_past_the_retention_window(): void
    {
        Storage::fake(config('social.media_disk'));

        $user = User::factory()->create(['avatar_path' => 'avatars/1/avatar.jpg']);
        Storage::disk(config('social.media_disk'))->put('avatars/1/avatar.jpg', 'fake-avatar');

        $post = Post::factory()->for($user)->create();
        $post->media()->create([
            'position' => 0,
            'original_path' => "posts/{$post->id}/original.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
        ]);
        Storage::disk(config('social.media_disk'))->put("posts/{$post->id}/original.jpg", 'fake-image');

        $user->posts()->delete();
        $user->delete();
        $user->deleted_at = now()->subDays(31);
        $user->saveQuietly();

        Artisan::call('users:prune-deleted');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        Storage::disk(config('social.media_disk'))->assertMissing('avatars/1/avatar.jpg');
        Storage::disk(config('social.media_disk'))->assertMissing("posts/{$post->id}/original.jpg");
    }

    public function test_prune_command_leaves_accounts_within_the_retention_window_alone(): void
    {
        $user = User::factory()->create();
        $user->delete();

        Artisan::call('users:prune-deleted');

        $this->assertSoftDeleted($user);
    }

    public function test_restore_command_restores_the_account_and_its_posts(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        $user->posts()->delete();
        $user->delete();

        Artisan::call('users:restore', ['id' => $user->id]);

        $this->assertNotSoftDeleted($user);
        $this->assertNotSoftDeleted($post);
    }
}
