<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\SavedCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavedCollectionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_collections_are_private_owner_only_resources(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/v1/collections', [
            'name' => 'UI inspiration', 'description' => 'Private references', 'visibility' => 'private',
        ])->assertCreated()->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.version', 1);
        $id = $created->json('data.id');

        $this->getJson('/api/v1/collections')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/collections/{$id}", ['name' => 'Interfaces', 'position' => 0, 'version' => 1])
            ->assertOk()->assertJsonPath('data.name', 'Interfaces')->assertJsonPath('data.version', 2);

        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/collections/{$id}", ['name' => 'Stolen', 'version' => 2])->assertNotFound();
        $this->getJson("/api/v1/collections/{$id}/posts")->assertNotFound();
        $this->deleteJson("/api/v1/collections/{$id}", ['version' => 2])->assertNotFound();
    }

    public function test_adding_is_idempotent_auto_saves_and_supports_private_notes(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->create();
        Sanctum::actingAs($owner);
        $collection = $this->collection($owner, 'Work');

        $created = $this->postJson("/api/v1/collections/{$collection->id}/posts/{$post->id}", [
            'collection_version' => 1, 'note' => 'Try this architecture',
        ])->assertCreated()->assertJsonPath('data.note', 'Try this architecture')->assertJsonPath('collection.version', 2);
        $itemVersion = $created->json('data.version');
        $this->assertDatabaseHas('saved_posts', ['user_id' => $owner->id, 'post_id' => $post->id]);

        $secondCollection = $this->collection($owner, 'Also useful');
        $this->postJson("/api/v1/collections/{$secondCollection->id}/posts/{$post->id}", ['collection_version' => 1])->assertCreated();
        $this->assertDatabaseCount('collection_items', 2);

        // A network retry with the original collection version is still idempotent.
        $this->postJson("/api/v1/collections/{$collection->id}/posts/{$post->id}", [
            'collection_version' => 1, 'note' => 'Ignored retry payload',
        ])->assertOk()->assertJsonPath('data.note', 'Try this architecture');
        $this->assertDatabaseCount('collection_items', 2);

        $this->patchJson("/api/v1/collections/{$collection->id}/posts/{$post->id}", [
            'collection_version' => 2, 'version' => $itemVersion, 'note' => 'Updated private note',
        ])->assertOk()->assertJsonPath('data.note', 'Updated private note')->assertJsonPath('collection.version', 3);
    }

    public function test_collection_and_item_ordering_is_normalized_with_conflict_detection(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $first = $this->collection($owner, 'First');
        $second = $this->collection($owner, 'Second');
        $third = $this->collection($owner, 'Third');

        $this->patchJson("/api/v1/collections/{$third->id}", ['position' => 0, 'version' => 1])->assertOk();
        $this->assertSame([$third->id, $first->id, $second->id], SavedCollection::query()->where('user_id', $owner->id)->orderBy('position')->pluck('id')->all());
        $this->patchJson("/api/v1/collections/{$third->id}", ['name' => 'Stale', 'version' => 1])->assertConflict();

        $collection = $third->fresh();
        $posts = Post::factory()->count(3)->create();
        foreach ($posts as $post) {
            $response = $this->postJson("/api/v1/collections/{$collection->id}/posts/{$post->id}", ['collection_version' => $collection->version])->assertCreated();
            $collection->version = $response->json('collection.version');
        }
        $lastItem = $collection->items()->where('post_id', $posts[2]->id)->firstOrFail();
        $this->patchJson("/api/v1/collections/{$collection->id}/posts/{$posts[2]->id}", [
            'collection_version' => $collection->version, 'version' => $lastItem->version, 'position' => 0,
        ])->assertOk();
        $this->assertSame($posts[2]->id, $collection->items()->orderBy('position')->firstOrFail()->post_id);
    }

    public function test_inaccessible_posts_disappear_without_destroying_membership(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        Sanctum::actingAs($owner);
        $collection = $this->collection($owner, 'References');
        $this->postJson("/api/v1/collections/{$collection->id}/posts/{$post->id}", ['collection_version' => 1])->assertCreated();

        $author->forceFill(['account_visibility' => 'private'])->save();
        $this->getJson("/api/v1/collections/{$collection->id}/posts")->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('collection_items', ['collection_id' => $collection->id, 'post_id' => $post->id]);

        $owner->following()->attach($author);
        $this->getJson("/api/v1/collections/{$collection->id}/posts")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_global_unsave_removes_memberships_and_normalizes_remaining_items(): void
    {
        $owner = User::factory()->create();
        $posts = Post::factory()->count(2)->create();
        Sanctum::actingAs($owner);
        $collection = $this->collection($owner, 'Ideas');
        $version = 1;
        foreach ($posts as $post) {
            $response = $this->postJson("/api/v1/collections/{$collection->id}/posts/{$post->id}", ['collection_version' => $version])->assertCreated();
            $version = $response->json('collection.version');
        }

        $this->deleteJson("/api/v1/posts/{$posts[0]->id}/save")->assertNoContent();

        $this->assertDatabaseMissing('collection_items', ['collection_id' => $collection->id, 'post_id' => $posts[0]->id]);
        $this->assertDatabaseHas('collection_items', ['collection_id' => $collection->id, 'post_id' => $posts[1]->id, 'position' => 0]);
        $this->assertDatabaseMissing('saved_posts', ['user_id' => $owner->id, 'post_id' => $posts[0]->id]);
    }

    public function test_removing_an_item_or_collection_does_not_globally_unsave_the_post(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->create();
        Sanctum::actingAs($owner);
        $collection = $this->collection($owner, 'Temporary folder');
        $item = $this->postJson("/api/v1/collections/{$collection->id}/posts/{$post->id}", ['collection_version' => 1])
            ->assertCreated();

        $this->deleteJson("/api/v1/collections/{$collection->id}/posts/{$post->id}", [
            'collection_version' => 2, 'version' => $item->json('data.version'),
        ])->assertNoContent();
        $this->assertDatabaseHas('saved_posts', ['user_id' => $owner->id, 'post_id' => $post->id]);

        $this->deleteJson("/api/v1/collections/{$collection->id}", ['version' => 3])->assertNoContent();
        $this->assertDatabaseHas('saved_posts', ['user_id' => $owner->id, 'post_id' => $post->id]);
    }

    private function collection(User $owner, string $name): SavedCollection
    {
        $response = $this->postJson('/api/v1/collections', ['name' => $name])->assertCreated();

        return SavedCollection::query()->where('user_id', $owner->id)->findOrFail($response->json('data.id'));
    }
}
