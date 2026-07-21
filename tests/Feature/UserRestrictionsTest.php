<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRestrictionType;
use App\Livewire\AdminUserDetail;
use App\Models\Conversation;
use App\Models\Post;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\UserRestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UserRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_write_restriction_affects_only_its_capability(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $post = Post::factory()->for($other)->create();
        $conversation = $this->activeConversation($user, $other);
        $service = app(UserRestrictionService::class);

        $service->create($user, $admin, UserRestrictionType::Posting, 'Posting cooldown');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/posts', ['images' => [UploadedFile::fake()->image('shot.jpg', 400, 800)]])->assertForbidden();
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Still allowed'])->assertCreated();
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Still allowed'])->assertCreated();

        $service->create($user, $admin, UserRestrictionType::Commenting, 'Comment cooldown');
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Blocked'])->assertForbidden();

        $service->create($user, $admin, UserRestrictionType::Messaging, 'Message cooldown');
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Blocked'])->assertForbidden();
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertOk();
    }

    public function test_expired_and_future_restrictions_do_not_apply_now(): void
    {
        $user = User::factory()->create();
        UserRestriction::create(['user_id' => $user->id, 'type' => 'posting', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(), 'reason' => 'Expired']);
        UserRestriction::create(['user_id' => $user->id, 'type' => 'posting', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'reason' => 'Future']);

        $this->assertFalse(app(UserRestrictionService::class)->isRestricted($user, UserRestrictionType::Posting));
    }

    public function test_overlapping_restrictions_remain_active_until_each_is_revoked_or_expired(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $service = app(UserRestrictionService::class);
        $first = $service->create($user, $admin, UserRestrictionType::Posting, 'First restriction', endsAt: now()->addDay());
        $service->create($user, $admin, UserRestrictionType::Posting, 'Second restriction', endsAt: now()->addDays(2));

        $service->revoke($first, $admin, 'First restriction overturned');

        $this->assertTrue($service->isRestricted($user, UserRestrictionType::Posting));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user_restriction.revoked', 'target_id' => $first->id]);
    }

    public function test_login_restriction_revokes_existing_tokens(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $user->createToken('mobile');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        app(UserRestrictionService::class)->create($user, $admin, UserRestrictionType::Login, 'Security investigation');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(app(UserRestrictionService::class)->isRestricted($user, UserRestrictionType::Login));
    }

    public function test_recommendation_restriction_excludes_posts_from_explore(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        app(UserRestrictionService::class)->create($author, $admin, UserRestrictionType::Recommendation, 'Recommendation safety review');
        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $post->id]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/explore')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_user_detail_shows_context_and_audits_restrictions_and_notes(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $user = User::factory()->create();
        $this->actingAs($admin);

        $this->get(route('users.show', $user->id))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee($user->email);

        Livewire::test(AdminUserDetail::class, ['user' => $user])
            ->set('restrictionType', 'commenting')
            ->set('restrictionReason', 'Repeated targeted harassment')
            ->set('durationDays', 14)
            ->call('createRestriction')
            ->set('supportNote', 'User contacted support about the moderation action.')
            ->call('addSupportNote');

        $this->assertDatabaseHas('user_restrictions', ['user_id' => $user->id, 'type' => 'commenting']);
        $this->assertDatabaseHas('user_support_notes', ['user_id' => $user->id, 'author_id' => $admin->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user_restriction.created']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.support_note_added']);
    }

    public function test_admin_cannot_restrict_themselves(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->expectException(HttpException::class);
        app(UserRestrictionService::class)->create($admin, $admin, UserRestrictionType::Login, 'Self restriction');
    }

    private function activeConversation(User $first, User $second): Conversation
    {
        $conversation = Conversation::create();
        $conversation->participants()->attach([$first->id, $second->id]);

        return $conversation;
    }
}
