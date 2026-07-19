<?php

namespace Tests\Feature\Actions;

use App\Actions\Posts\CreatePost;
use App\Actions\Posts\PurgePost;
use App\Actions\Posts\StagePostMedia;
use App\Actions\Posts\SyncPostHashtags;
use App\Actions\Posts\SyncPostMentions;
use App\Contracts\MediaFileStore;
use App\Data\Posts\CreatePostData;
use App\Enums\PostPurgeOutcome;
use App\Enums\PostPurgeStatus;
use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\MediaCleanupTask;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\Storage\LaravelMediaFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PostLifecycleActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_is_staged_before_the_post_transaction_and_jobs_are_after_commit(): void
    {
        Queue::fake();
        $images = Mockery::mock(ImageProcessingService::class);
        $images->shouldReceive('storeOriginal')->once()->andReturnUsing(function (): array {
            $this->assertDatabaseCount('posts', 0);

            return ['path' => 'posts/op/original.jpg', 'width' => 800, 'height' => 800, 'mime' => 'image/jpeg', 'size' => 100];
        });
        $action = new CreatePost(
            new StagePostMedia($images, app(MediaFileStore::class)),
            app(SyncPostHashtags::class),
            app(SyncPostMentions::class),
        );

        $post = $action(User::factory()->create(), new CreatePostData(null, [UploadedFile::fake()->image('shot.jpg')]));

        Queue::assertPushed(GeneratePostMediaThumbnail::class, fn (GeneratePostMediaThumbnail $job): bool => $job->afterCommit === true);
        $this->assertSame($post->id, PostMedia::firstOrFail()->post_id);
    }

    public function test_transaction_failure_cleans_staged_originals(): void
    {
        Storage::fake('public');
        Queue::fake();
        $hashtags = Mockery::mock(SyncPostHashtags::class);
        $hashtags->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('database workflow failed'));
        $action = new CreatePost(app(StagePostMedia::class), $hashtags, app(SyncPostMentions::class));

        try {
            $action(User::factory()->create(), new CreatePostData('#tag', [UploadedFile::fake()->image('shot.jpg', 800, 800)]));
            $this->fail('Expected post creation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database workflow failed', $exception->getMessage());
        }

        $this->assertDatabaseCount('posts', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_cleanup_failure_keeps_a_retryable_ledger_without_masking_the_workflow_error(): void
    {
        Storage::fake('public');
        $hashtags = Mockery::mock(SyncPostHashtags::class);
        $hashtags->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('workflow failed'));
        $files = Mockery::mock(MediaFileStore::class);
        $files->shouldReceive('deleteDirectory')->once()->andThrow(new RuntimeException('storage unavailable'));
        $action = new CreatePost(new StagePostMedia(app(ImageProcessingService::class), $files), $hashtags, app(SyncPostMentions::class));

        try {
            $action(User::factory()->create(), new CreatePostData(null, [UploadedFile::fake()->image('shot.jpg')]));
            $this->fail('Expected workflow failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('workflow failed', $exception->getMessage());
        }

        $task = MediaCleanupTask::firstOrFail();
        $this->assertStringContainsString('storage unavailable', $task->last_error);
    }

    public function test_purge_is_idempotent_when_files_or_rows_are_already_missing(): void
    {
        Storage::fake('public');
        $post = $this->trashedPost('posts/missing.jpg');
        $action = app(PurgePost::class);

        $this->assertSame(PostPurgeOutcome::Purged, $action($post->id));
        $this->assertSame(PostPurgeOutcome::AlreadyGone, $action($post->id));
    }

    public function test_purge_returns_busy_when_another_worker_holds_the_lock(): void
    {
        $post = $this->trashedPost('posts/busy.jpg');
        $lock = Cache::lock("post-purge:{$post->id}", 300);
        $lock->get();

        try {
            $this->assertSame(PostPurgeOutcome::Busy, app(PurgePost::class)($post->id));
            $this->assertSoftDeleted('posts', ['id' => $post->id]);
        } finally {
            $lock->release();
        }
    }

    public function test_storage_failure_is_persisted_and_can_be_retried(): void
    {
        Storage::fake('public');
        $post = $this->trashedPost('posts/retry.jpg');
        Storage::disk('public')->put('posts/retry.jpg', 'x');
        $failingStore = new class implements MediaFileStore
        {
            public function deletePaths(array $paths): void
            {
                throw new RuntimeException('storage unavailable');
            }

            public function deleteDirectory(string $directory): void
            {
                throw new RuntimeException('storage unavailable');
            }
        };

        try {
            (new PurgePost($failingStore))($post->id);
            $this->fail('Expected purge to fail.');
        } catch (RuntimeException) {
            $failed = Post::onlyTrashed()->findOrFail($post->id);
            $this->assertSame(PostPurgeStatus::Failed, $failed->purge_status);
            $this->assertStringContainsString('storage unavailable', $failed->purge_error);
        }

        $this->assertSame(PostPurgeOutcome::Purged, (new PurgePost(app(LaravelMediaFileStore::class)))($post->id));
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    public function test_prune_command_continues_after_one_post_fails(): void
    {
        Storage::fake('public');
        $failed = $this->trashedPost('posts/fail.jpg');
        $successful = $this->trashedPost('posts/success.jpg');
        $failed->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();
        $successful->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();
        Storage::disk('public')->put('posts/fail.jpg', 'x');
        Storage::disk('public')->put('posts/success.jpg', 'x');
        $realStore = app(LaravelMediaFileStore::class);
        $this->app->instance(MediaFileStore::class, new class($realStore) implements MediaFileStore
        {
            public function __construct(private readonly MediaFileStore $realStore) {}

            public function deletePaths(array $paths): void
            {
                if (in_array('posts/fail.jpg', $paths, true)) {
                    throw new RuntimeException('selected failure');
                }

                $this->realStore->deletePaths($paths);
            }

            public function deleteDirectory(string $directory): void
            {
                $this->realStore->deleteDirectory($directory);
            }
        });

        $this->artisan('posts:prune-deleted')->assertExitCode(1);

        $this->assertSoftDeleted('posts', ['id' => $failed->id]);
        $this->assertDatabaseMissing('posts', ['id' => $successful->id]);
    }

    public function test_account_is_retained_when_a_child_post_cannot_be_purged(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();
        PostMedia::factory()->create(['post_id' => $post->id, 'original_path' => 'posts/account-fail.jpg']);
        $post->forceFill([
            'deleted_at' => now()->subDays(31),
            'account_deleted_at' => now()->subDays(31),
        ])->saveQuietly();
        $user->delete();
        $user->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();
        $this->app->instance(MediaFileStore::class, new class implements MediaFileStore
        {
            public function deletePaths(array $paths): void
            {
                throw new RuntimeException('storage unavailable');
            }

            public function deleteDirectory(string $directory): void
            {
                throw new RuntimeException('storage unavailable');
            }
        });

        $this->artisan('users:prune-deleted')->assertExitCode(1);

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSoftDeleted('posts', ['id' => $post->id]);
    }

    private function trashedPost(string $path): Post
    {
        $post = Post::factory()->create();
        PostMedia::factory()->create(['post_id' => $post->id, 'original_path' => $path]);
        $post->delete();

        return $post;
    }
}
