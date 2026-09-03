<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\HashtagModerationState;
use App\Livewire\ModerationTagsTable;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use App\Services\HashtagModerationService;
use App\Services\HashtagService;
use App\Services\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class HashtagModerationTest extends TestCase
{
    use RefreshDatabase;

    private function taggedPost(Hashtag $hashtag): Post
    {
        $post = Post::factory()->create();
        $post->hashtags()->attach($hashtag);

        return $post;
    }

    private function moderate(Hashtag $hashtag, HashtagModerationState $state): void
    {
        app(HashtagModerationService::class)->setState(
            $hashtag,
            User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]),
            $state,
            'Coordinated harassment campaign.',
        );
    }

    public function test_a_clear_tag_trends_and_is_searchable(): void
    {
        $hashtag = Hashtag::factory()->create(['name' => 'sunset']);
        $this->taggedPost($hashtag);

        $this->assertCount(1, app(HashtagService::class)->trending());
        $this->assertCount(1, app(SearchService::class)->hashtags('sunset')->items());
    }

    public function test_a_de_ranked_tag_leaves_trending_and_search_but_keeps_its_page(): void
    {
        $hashtag = Hashtag::factory()->create(['name' => 'sunset']);
        $this->taggedPost($hashtag);
        $this->moderate($hashtag, HashtagModerationState::NotRecommended);

        $this->assertCount(0, app(HashtagService::class)->trending());
        $this->assertCount(0, app(SearchService::class)->hashtags('sunset')->items());

        // The reach is removed; the tag itself is still reachable for anyone who has the link.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/hashtags/sunset')->assertOk();
        $this->getJson('/api/v1/hashtags/sunset/posts')->assertOk();
    }

    public function test_a_blocked_tag_also_stops_resolving_over_the_api(): void
    {
        $hashtag = Hashtag::factory()->create(['name' => 'sunset']);
        $this->taggedPost($hashtag);
        $this->moderate($hashtag, HashtagModerationState::Blocked);

        $this->assertCount(0, app(HashtagService::class)->trending());
        $this->assertCount(0, app(SearchService::class)->hashtags('sunset')->items());

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/hashtags/sunset')->assertNotFound();
        $this->getJson('/api/v1/hashtags/sunset/posts')->assertNotFound();
        $this->postJson('/api/v1/hashtags/sunset/follow')->assertNotFound();
    }

    public function test_unfollowing_a_blocked_tag_still_works(): void
    {
        $hashtag = Hashtag::factory()->create(['name' => 'sunset']);
        $user = User::factory()->create();
        $user->followedHashtags()->attach($hashtag);
        $this->moderate($hashtag, HashtagModerationState::Blocked);

        // Otherwise a user who followed the tag before it was blocked is stuck with an
        // entry they can neither open nor remove.
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/hashtags/sunset/follow')->assertNoContent();
        $this->assertSame(0, $user->followedHashtags()->count());
    }

    public function test_blocking_a_tag_does_not_touch_the_posts_carrying_it(): void
    {
        $hashtag = Hashtag::factory()->create(['name' => 'sunset']);
        $post = $this->taggedPost($hashtag);
        $this->moderate($hashtag, HashtagModerationState::Blocked);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'deleted_at' => null]);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/posts/{$post->id}")->assertOk();
    }

    public function test_clearing_a_tag_restores_it_to_discovery(): void
    {
        $hashtag = Hashtag::factory()->create(['name' => 'sunset']);
        $this->taggedPost($hashtag);
        $this->moderate($hashtag, HashtagModerationState::Blocked);
        $this->moderate($hashtag, HashtagModerationState::Clear);

        $this->assertCount(1, app(HashtagService::class)->trending());
        $this->assertCount(1, app(SearchService::class)->hashtags('sunset')->items());
    }

    public function test_moderating_a_tag_requires_a_reason_and_writes_an_audit_record(): void
    {
        $hashtag = Hashtag::factory()->create();
        $moderator = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);

        try {
            app(HashtagModerationService::class)->setState($hashtag, $moderator, HashtagModerationState::Blocked, ' ');
            $this->fail('Expected a validation exception for a missing reason.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $this->assertSame(HashtagModerationState::Clear, $hashtag->fresh()?->moderation_state);

        app(HashtagModerationService::class)->setState($hashtag, $moderator, HashtagModerationState::Blocked, 'Slur variant.');

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'hashtag.blocked',
            'actor_id' => $moderator->id,
            'target_id' => $hashtag->id,
        ]);
        $this->assertSame($moderator->id, $hashtag->fresh()?->moderated_by);
    }

    public function test_moderation_state_is_never_mass_assignable(): void
    {
        $hashtag = Hashtag::query()->create([
            'name' => 'sneaky',
            'moderation_state' => HashtagModerationState::Blocked->value,
        ]);

        $this->assertSame(HashtagModerationState::Clear, $hashtag->fresh()?->moderation_state);
    }

    public function test_the_tag_page_requires_view_moderation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('moderation.tags.index'))->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::ReadOnlyAuditor]));
        $this->get(route('moderation.tags.index'))->assertOk();
    }

    public function test_an_auditor_cannot_change_a_tag_state(): void
    {
        $hashtag = Hashtag::factory()->create();

        Livewire::actingAs(User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::ReadOnlyAuditor]))
            ->test(ModerationTagsTable::class)
            ->call('startAction', $hashtag->id, 'blocked')
            ->assertForbidden();

        $this->assertSame(HashtagModerationState::Clear, $hashtag->fresh()?->moderation_state);
    }

    public function test_a_moderator_blocks_a_tag_from_the_dashboard(): void
    {
        $hashtag = Hashtag::factory()->create(['name' => 'raid']);
        $this->taggedPost($hashtag);

        Livewire::actingAs(User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]))
            ->test(ModerationTagsTable::class)
            ->assertSee('raid')
            ->call('startAction', $hashtag->id, 'blocked')
            ->set('reason', 'Brigading campaign hashtag.')
            ->call('applyState');

        $this->assertSame(HashtagModerationState::Blocked, $hashtag->fresh()?->moderation_state);
        $this->assertSame('Brigading campaign hashtag.', $hashtag->fresh()?->moderation_reason);
    }

    public function test_the_dashboard_ranks_tags_by_recent_activity_and_keeps_its_counts(): void
    {
        $quiet = Hashtag::factory()->create(['name' => 'quiet']);
        $busy = Hashtag::factory()->create(['name' => 'busy']);
        $this->taggedPost($quiet);
        $this->taggedPost($busy);
        $this->taggedPost($busy);

        $component = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]))
            ->test(ModerationTagsTable::class);

        $hashtags = $component->viewData('hashtags');
        $this->assertSame('busy', $hashtags->first()->name);
        $this->assertSame(2, (int) $hashtags->first()->recent_posts);
        // withCount() adds its subquery via addSelect — regression guard for the select()
        // ordering that would otherwise silently null this out.
        $this->assertSame(2, (int) $hashtags->first()->posts_count);
    }
}
