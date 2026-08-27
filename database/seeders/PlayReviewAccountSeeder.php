<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\ScreenshotCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The demo account Google Play reviewers sign in with (Play Console → App access).
 *
 * Deliberately NOT wired into DatabaseSeeder — it creates a real, permanent production account
 * and should only run when invoked explicitly:
 *
 *     php artisan db:seed --class=PlayReviewAccountSeeder --force
 *
 * Idempotent: re-running refreshes the password and demo content rather than duplicating the
 * account, so a later `--force` run before a resubmission is safe.
 *
 * Two things reviewers depend on:
 *  - `email_verified_at` is set, so the account never hits a verification wall mid-review.
 *  - It has published posts. An empty feed on first launch reads as a broken app and is a real
 *    rejection risk.
 *
 * Media points at absolute placeholder URLs (PostMedia::originalUrl() returns those verbatim),
 * so seeding needs no R2 round trip.
 */
class PlayReviewAccountSeeder extends Seeder
{
    private const EMAIL = 'playreview@akukas.ly';

    private const USERNAME = 'playreview';

    /**
     * Must match the password entered in Play Console → App access. Kept here rather than in
     * env() because `env()` outside config/ returns null once the config is cached, and a
     * silently-null password would seed an unusable account right before a review.
     */
    private const PASSWORD = '12312345';

    /** Marks the demo posts so re-running can replace exactly these and nothing else. */
    private const SOURCE = 'play-review-seed';

    public function run(): void
    {
        $user = User::withTrashed()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Play Review',
                'username' => self::USERNAME,
                'password' => Hash::make(self::PASSWORD),
                'deleted_at' => null,
            ],
        );

        // Verified up front: a reviewer cannot receive our verification mail.
        $user->forceFill([
            'email_verified_at' => $user->email_verified_at ?? now(),
            'is_admin' => false,
        ])->save();

        Post::withoutGlobalScopes()->withTrashed()
            ->where('user_id', $user->id)
            ->where('source_application', self::SOURCE)
            ->forceDelete();

        $category = ScreenshotCategory::query()->first();

        $samples = [
            'Saved this thread so I can find it again later.',
            'Trying out the new capture shortcut — one tap and it is here.',
            'Keeping my receipts in one place instead of scattered in the gallery.',
        ];

        foreach ($samples as $index => $caption) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'caption' => $caption,
                'category_id' => $category?->id,
                'source_application' => self::SOURCE,
                // Posts default to STATUS_PROCESSING; only READY ones surface in feeds.
                'status' => Post::STATUS_READY,
                'created_at' => now()->subDays($index + 1),
            ]);

            $seed = 'akukas-review-'.$index;
            PostMedia::query()->create([
                'post_id' => $post->id,
                'position' => 0,
                'original_path' => "https://picsum.photos/seed/{$seed}/640/1136",
                'thumbnail_path' => "https://picsum.photos/seed/{$seed}/320/568",
                'width' => 640,
                'height' => 1136,
                'mime_type' => 'image/jpeg',
                'size_bytes' => 240_000,
                'status' => PostMedia::STATUS_READY,
                'ocr_status' => PostMedia::PROCESSING_READY,
                'hash_status' => PostMedia::PROCESSING_READY,
                'safety_status' => PostMedia::SAFETY_CLEAR,
                'alt_text' => 'Sample screenshot shared by the review account.',
            ]);
        }

        $this->command->info('Play review account ready: '.self::EMAIL.' ('.count($samples).' demo posts)');
    }
}
