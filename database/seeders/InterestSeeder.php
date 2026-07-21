<?php

namespace Database\Seeders;

use App\Models\Hashtag;
use App\Models\Interest;
use App\Models\ScreenshotCategory;
use Illuminate\Database\Seeder;

class InterestSeeder extends Seeder
{
    /** @var array<string, array{name: string, icon: string, category: string, tags: list<string>}> */
    public const INTERESTS = [
        'technology' => ['name' => 'Technology', 'icon' => 'devices', 'category' => 'technology', 'tags' => ['technology', 'flutter', 'coding', 'ai', 'cybersecurity']],
        'sports' => ['name' => 'Sports', 'icon' => 'sports_soccer', 'category' => 'sports', 'tags' => ['sports', 'football', 'fitness', 'motorsport', 'running']],
        'cooking' => ['name' => 'Cooking', 'icon' => 'restaurant', 'category' => 'lifestyle', 'tags' => ['cooking', 'recipes', 'food', 'baking', 'healthy']],
        'fashion' => ['name' => 'Fashion', 'icon' => 'checkroom', 'category' => 'design', 'tags' => ['fashion', 'style', 'outfits', 'beauty', 'shopping']],
        'gaming' => ['name' => 'Gaming', 'icon' => 'sports_esports', 'category' => 'gaming', 'tags' => ['gaming', 'indiegames', 'steam', 'gamedev', 'pixelart']],
        'design' => ['name' => 'Design', 'icon' => 'palette', 'category' => 'design', 'tags' => ['design', 'uidesign', 'figma', 'typography', 'branding']],
        'business' => ['name' => 'Business', 'icon' => 'business_center', 'category' => 'business', 'tags' => ['business', 'startup', 'marketing', 'growth', 'entrepreneur']],
        'education' => ['name' => 'Education', 'icon' => 'school', 'category' => 'education', 'tags' => ['education', 'learning', 'study', 'science', 'students']],
        'travel' => ['name' => 'Travel', 'icon' => 'flight', 'category' => 'lifestyle', 'tags' => ['travel', 'maps', 'architecture', 'culture', 'photography']],
        'music' => ['name' => 'Music', 'icon' => 'music_note', 'category' => 'entertainment', 'tags' => ['music', 'playlists', 'concerts', 'audio', 'artists']],
        'movies' => ['name' => 'Movies & TV', 'icon' => 'movie', 'category' => 'entertainment', 'tags' => ['movies', 'cinema', 'tv', 'reviews', 'streaming']],
        'books' => ['name' => 'Books & Writing', 'icon' => 'menu_book', 'category' => 'education', 'tags' => ['books', 'reading', 'writing', 'literature', 'quotes']],
        'finance' => ['name' => 'Finance', 'icon' => 'monitoring', 'category' => 'business', 'tags' => ['finance', 'investing', 'markets', 'fintech', 'charts']],
        'news' => ['name' => 'News', 'icon' => 'newspaper', 'category' => 'news', 'tags' => ['news', 'world', 'economy', 'analysis']],
        'art' => ['name' => 'Art', 'icon' => 'brush', 'category' => 'design', 'tags' => ['art', 'illustration', 'drawing', 'creative', 'colors']],
        'wellness' => ['name' => 'Wellness', 'icon' => 'self_improvement', 'category' => 'lifestyle', 'tags' => ['wellness', 'mindfulness', 'mentalhealth', 'selfcare', 'health']],
    ];

    public function run(): void
    {
        foreach (self::INTERESTS as $position => $definition) {
            $category = ScreenshotCategory::query()->firstOrCreate(
                ['slug' => $definition['category']],
                ['name' => str($definition['category'])->headline(), 'sort_order' => 100, 'is_active' => true],
            );
            $interest = Interest::query()->updateOrCreate(['slug' => $position], [
                'name' => $definition['name'],
                'icon' => $definition['icon'],
                'description' => "Screenshots about {$definition['name']}.",
                'sort_order' => array_search($position, array_keys(self::INTERESTS), true),
                'is_active' => true,
            ]);
            $interest->categories()->sync([$category->id => ['weight' => 100]]);
            $tags = collect($definition['tags'])->map(fn (string $name) => Hashtag::query()->firstOrCreate(['name' => $name]));
            $interest->hashtags()->sync($tags->mapWithKeys(fn (Hashtag $tag): array => [$tag->id => ['weight' => 100]])->all());
        }
    }
}
