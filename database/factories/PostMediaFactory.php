<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostMedia>
 */
class PostMediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'position' => 0,
            'original_path' => 'posts/'.fake()->uuid().'.jpg',
            'thumbnail_path' => null,
            'width' => 1080,
            'height' => 1920,
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(10_000, 2_000_000),
            'status' => PostMedia::STATUS_PENDING,
        ];
    }
}
