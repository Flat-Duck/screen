<?php

namespace Database\Seeders;

use App\Models\Hashtag;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Scopes\NotArchivedScope;
use App\Models\ScreenshotCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class PostSeeder extends Seeder
{
    public const POSTS_PER_USER = 10;

    public const SOURCE = 'Screenshut Seeder';

    /** @var array<string, string> */
    private const CATEGORIES = [
        'technology' => 'Technology', 'design' => 'Design', 'entertainment' => 'Entertainment',
        'education' => 'Education', 'lifestyle' => 'Lifestyle', 'news' => 'News',
        'gaming' => 'Gaming', 'business' => 'Business', 'sports' => 'Sports', 'other' => 'Other',
    ];

    public function run(): void
    {
        Post::withoutGlobalScope(NotArchivedScope::class)->withTrashed()->where('source_application', self::SOURCE)->forceDelete();

        $categories = collect(self::CATEGORIES)->mapWithKeys(fn (string $name, string $slug) => [
            $slug => ScreenshotCategory::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true, 'sort_order' => array_search($slug, array_keys(self::CATEGORIES), true)]),
        ]);
        $allTopicNames = collect(UserSeeder::SPECIALTIES)->flatten()->unique()->values();
        $hashtags = $allTopicNames->mapWithKeys(fn (string $name) => [$name => Hashtag::query()->firstOrCreate(['name' => $name])]);
        $users = User::query()->whereIn('username', UserSeeder::USERNAMES)->get()->keyBy('username');

        foreach ($users as $username => $user) {
            $specialties = UserSeeder::SPECIALTIES[$username];
            for ($index = 0; $index < self::POSTS_PER_USER; $index++) {
                $primaryTags = $index < 8
                    ? collect(array_slice($specialties, 0, 4))->merge($index % 2 === 0 ? [$specialties[4]] : [])
                    : collect($specialties)->shuffle()->take(random_int(3, 5));
                $extraTags = $allTopicNames->diff($specialties)->shuffle()->take(random_int(0, 2));
                $tagNames = $primaryTags->merge($extraTags)->unique()->values();
                $createdAt = now()->subDays(random_int(0, 89))->subMinutes(random_int(0, 1439));
                $category = $this->categoryFor($specialties, $categories);
                $post = Post::factory()->for($user)->create([
                    'caption' => fake()->sentence(random_int(5, 12)).' '.$tagNames->map(fn (string $tag) => '#'.$tag)->implode(' '),
                    'status' => Post::STATUS_READY,
                    'category_id' => $category->id,
                    'source_application' => self::SOURCE,
                    'source_url' => 'https://example.com/demo/'.$username.'/'.$index,
                    'content_warning' => $index % 19 === 0 ? 'spoiler' : null,
                    'comments_enabled' => $index % 13 !== 0,
                    'reposts_enabled' => $index % 11 !== 0,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
                $post->hashtags()->sync($tagNames->map(fn (string $tag) => $hashtags[$tag]->id));

                for ($position = 0; $position < random_int(1, 3); $position++) {
                    $seed = "{$username}-{$index}-{$position}";
                    PostMedia::query()->create([
                        'post_id' => $post->id, 'position' => $position,
                        'original_path' => "https://picsum.photos/seed/{$seed}/640/1136",
                        'thumbnail_path' => "https://picsum.photos/seed/{$seed}/320/568",
                        'width' => 640, 'height' => 1136, 'mime_type' => 'image/jpeg',
                        'size_bytes' => random_int(80_000, 900_000), 'status' => PostMedia::STATUS_READY,
                        'ocr_status' => PostMedia::PROCESSING_READY, 'hash_status' => PostMedia::PROCESSING_READY,
                        'safety_status' => $index % 23 === 0 ? PostMedia::SAFETY_WARNING : PostMedia::SAFETY_CLEAR,
                        'alt_text' => "Demo screenshot about {$primaryTags->first()} by {$user->name}.",
                    ]);
                }
            }
        }
    }

    /**
     * @param  list<string>  $specialties
     * @param  Collection<string, ScreenshotCategory>  $categories
     */
    private function categoryFor(array $specialties, Collection $categories): ScreenshotCategory
    {
        $slug = match (true) {
            count(array_intersect($specialties, ['flutter', 'coding', 'ai', 'security', 'cars'])) > 0 => 'technology',
            count(array_intersect($specialties, ['design', 'art', 'fashion'])) > 0 => 'design',
            count(array_intersect($specialties, ['gaming'])) > 0 => 'gaming',
            count(array_intersect($specialties, ['education', 'books'])) > 0 => 'education',
            count(array_intersect($specialties, ['finance', 'business'])) > 0 => 'business',
            count(array_intersect($specialties, ['football', 'fitness'])) > 0 => 'sports',
            count(array_intersect($specialties, ['news'])) > 0 => 'news',
            count(array_intersect($specialties, ['music', 'movies', 'memes'])) > 0 => 'entertainment',
            default => 'lifestyle',
        };

        return $categories[$slug];
    }
}
