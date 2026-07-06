<?php

namespace Tests\Feature\Console;

use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrunePendingDeletedPostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_purges_posts_past_the_retention_window_and_removes_their_files(): void
    {
        Storage::fake('public');

        $expired = Post::factory()->create();
        $expiredMedia = PostMedia::factory()->create([
            'post_id' => $expired->id,
            'original_path' => 'posts/expired/original.jpg',
            'thumbnail_path' => 'posts/expired/thumb.jpg',
        ]);
        Storage::disk('public')->put($expiredMedia->original_path, 'x');
        Storage::disk('public')->put($expiredMedia->thumbnail_path, 'x');
        $expired->delete();
        $expired->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

        $withinWindow = Post::factory()->create();
        $withinWindow->delete();

        $this->artisan('posts:prune-deleted')->assertExitCode(0);

        $this->assertDatabaseMissing('posts', ['id' => $expired->id]);
        $this->assertSoftDeleted('posts', ['id' => $withinWindow->id]);
        Storage::disk('public')->assertMissing($expiredMedia->original_path);
        Storage::disk('public')->assertMissing($expiredMedia->thumbnail_path);
    }
}
