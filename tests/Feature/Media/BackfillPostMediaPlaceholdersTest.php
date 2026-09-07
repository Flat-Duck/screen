<?php

namespace Tests\Feature\Media;

use App\Jobs\ComputePostMediaPlaceholder;
use App\Models\PostMedia;
use App\Services\ImageProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillPostMediaPlaceholdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_only_the_media_missing_a_placeholder(): void
    {
        Queue::fake();
        $missing = PostMedia::factory()->create(['thumbhash' => null]);
        PostMedia::factory()->create(['thumbhash' => 'already-has-one']);

        $this->artisan('media:backfill-placeholders')
            ->expectsOutputToContain('Queued 1 of 1')
            ->assertSuccessful();

        Queue::assertPushed(
            ComputePostMediaPlaceholder::class,
            fn (ComputePostMediaPlaceholder $job): bool => $job->postMediaId === $missing->id,
        );
        Queue::assertPushed(ComputePostMediaPlaceholder::class, 1);
    }

    /** So a large backfill can be fed to a live queue in controlled batches. */
    public function test_the_limit_option_caps_how_much_is_queued(): void
    {
        Queue::fake();
        PostMedia::factory()->count(5)->create(['thumbhash' => null]);

        $this->artisan('media:backfill-placeholders', ['--limit' => 2])
            ->expectsOutputToContain('Queued 2 of 5')
            ->assertSuccessful();

        Queue::assertPushed(ComputePostMediaPlaceholder::class, 2);
    }

    public function test_it_says_so_and_queues_nothing_when_there_is_nothing_to_do(): void
    {
        Queue::fake();
        PostMedia::factory()->create(['thumbhash' => 'present']);

        $this->artisan('media:backfill-placeholders')
            ->expectsOutputToContain('already has a placeholder')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** Re-running over a partly-finished batch must not recompute what already succeeded. */
    public function test_the_job_skips_media_that_already_has_a_placeholder(): void
    {
        $media = PostMedia::factory()->create(['thumbhash' => 'untouched']);

        (new ComputePostMediaPlaceholder($media->id))->handle(app(ImageProcessingService::class));

        $this->assertSame('untouched', $media->fresh()->thumbhash);
    }
}
