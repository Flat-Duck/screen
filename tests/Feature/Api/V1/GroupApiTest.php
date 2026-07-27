<?php

namespace Tests\Feature\Api\V1;

use App\Models\Group;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_group_auto_joins_the_creator_as_admin(): void
    {
        $creator = User::factory()->create();
        Sanctum::actingAs($creator);

        $response = $this->postJson('/api/v1/groups', ['name' => 'Photography'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Photography')
            ->assertJsonPath('data.visibility', 'public')
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.is_member', true)
            ->assertJsonPath('data.is_admin', true);

        $groupId = $response->json('data.id');
        $this->assertDatabaseHas('group_members', ['group_id' => $groupId, 'user_id' => $creator->id, 'role' => 'admin']);
    }

    public function test_discover_lists_groups_and_annotates_the_viewers_own_membership(): void
    {
        $creator = User::factory()->create();
        $viewer = User::factory()->create();
        Sanctum::actingAs($creator);
        $group = $this->createGroup($creator, 'Gaming');

        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/groups')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Gaming')
            ->assertJsonPath('data.0.is_member', false);

        $this->postJson("/api/v1/groups/{$group->id}/membership")->assertNoContent();
        $this->getJson('/api/v1/groups?mine=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/groups/{$group->id}")->assertOk()->assertJsonPath('data.is_member', true)->assertJsonPath('data.member_count', 2);

        $this->deleteJson("/api/v1/groups/{$group->id}/membership")->assertNoContent();
        $this->getJson("/api/v1/groups/{$group->id}")->assertOk()->assertJsonPath('data.is_member', false)->assertJsonPath('data.member_count', 1);
        $this->getJson('/api/v1/groups?mine=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_joining_twice_is_idempotent_and_does_not_inflate_member_count(): void
    {
        $creator = User::factory()->create();
        Sanctum::actingAs($creator);
        $group = $this->createGroup($creator, 'Memes');
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/groups/{$group->id}/membership")->assertNoContent();
        $this->postJson("/api/v1/groups/{$group->id}/membership")->assertNoContent();
        $this->assertSame(2, $group->fresh()->member_count);
        $this->assertDatabaseCount('group_members', 2);
    }

    public function test_sharing_a_post_requires_membership_and_visibility(): void
    {
        $creator = User::factory()->create();
        Sanctum::actingAs($creator);
        $group = $this->createGroup($creator, 'Design');

        $author = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        // Not a member yet — forbidden even though the post itself is visible.
        $this->postJson("/api/v1/groups/{$group->id}/posts/{$post->id}")->assertForbidden();

        $this->postJson("/api/v1/groups/{$group->id}/membership")->assertNoContent();
        $this->postJson("/api/v1/groups/{$group->id}/posts/{$post->id}")->assertNoContent();
        $this->assertDatabaseHas('group_posts', ['group_id' => $group->id, 'post_id' => $post->id, 'shared_by_user_id' => $outsider->id]);

        // Idempotent retry doesn't duplicate the row.
        $this->postJson("/api/v1/groups/{$group->id}/posts/{$post->id}")->assertNoContent();
        $this->assertDatabaseCount('group_posts', 1);

        $this->getJson("/api/v1/groups/{$group->id}/posts")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $post->id);
    }

    public function test_group_feed_hides_posts_the_viewer_cannot_see(): void
    {
        $creator = User::factory()->create();
        Sanctum::actingAs($creator);
        $group = $this->createGroup($creator, 'Private Circle');

        $privateAuthor = User::factory()->create(['account_visibility' => 'private']);
        $hiddenPost = Post::factory()->for($privateAuthor)->create();
        Sanctum::actingAs($privateAuthor);
        $this->postJson("/api/v1/groups/{$group->id}/membership")->assertNoContent();
        $this->postJson("/api/v1/groups/{$group->id}/posts/{$hiddenPost->id}")->assertNoContent();

        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $this->postJson("/api/v1/groups/{$group->id}/membership")->assertNoContent();
        $this->getJson("/api/v1/groups/{$group->id}/posts")->assertOk()->assertJsonCount(0, 'data');
    }

    private function createGroup(User $creator, string $name): Group
    {
        $group = Group::create(['creator_id' => $creator->id, 'name' => $name, 'visibility' => 'public', 'member_count' => 1]);
        $group->members()->create(['user_id' => $creator->id, 'role' => 'admin']);

        return $group;
    }
}
