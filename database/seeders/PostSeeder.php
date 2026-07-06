<?php

namespace Database\Seeders;

use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\ImageProcessingService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

class PostSeeder extends Seeder
{
    private const POSTS_PER_USER = 10;

    /**
     * Creates 10 posts per demo user, each with 1-3 real (downloaded) images run through
     * the same ImageProcessingService/GeneratePostMediaThumbnail pipeline production uses,
     * so seeded posts end up READY with a real original + thumbnail on disk.
     */
    public function run(ImageProcessingService $images): void
    {
        $users = User::whereIn('username', UserSeeder::USERNAMES)->get();

        foreach ($users as $user) {
            for ($i = 0; $i < self::POSTS_PER_USER; $i++) {
                $post = Post::factory()->create([
                    'user_id' => $user->id,
                    'status' => Post::STATUS_PROCESSING,
                ]);

                $mediaCount = random_int(1, 3);

                for ($position = 0; $position < $mediaCount; $position++) {
                    $this->attachMedia($post, $position, $images);
                }
            }
        }
    }

    private function attachMedia(Post $post, int $position, ImageProcessingService $images): void
    {
        $width = 1080;
        $height = random_int(1080, 1620);

        $tmpPath = tempnam(sys_get_temp_dir(), 'seed-media-');
        file_put_contents($tmpPath, $this->fetchImageBytes($width, $height));

        $file = new UploadedFile($tmpPath, Str::uuid().'.jpg', 'image/jpeg', null, true);
        $stored = $images->storeOriginal($file, 'posts');
        @unlink($tmpPath);

        $media = PostMedia::create([
            'post_id' => $post->id,
            'position' => $position,
            'original_path' => $stored['path'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'mime_type' => $stored['mime'],
            'size_bytes' => $stored['size'],
            'status' => PostMedia::STATUS_PENDING,
        ]);

        (new GeneratePostMediaThumbnail($media->id))->handle($images);
    }

    /** Pulls a random real photo from Lorem Picsum; falls back to a generated solid-color
     *  placeholder if the network is unavailable, so seeding never hard-fails offline. */
    private function fetchImageBytes(int $width, int $height): string
    {
        try {
            $response = Http::timeout(5)->get(
                'https://picsum.photos/seed/'.Str::random(12)."/{$width}/{$height}"
            );

            if ($response->successful()) {
                return $response->body();
            }
        } catch (Throwable) {
            // network unavailable — fall through to local placeholder
        }

        return $this->placeholderBytes($width, $height);
    }

    private function placeholderBytes(int $width, int $height): string
    {
        $manager = new ImageManager(new Driver);
        $color = sprintf('#%06x', random_int(0, 0xFFFFFF));

        return (string) $manager->create($width, $height)->fill($color)->toJpeg();
    }
}
