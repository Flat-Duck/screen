<?php

namespace Database\Factories;

use App\Models\PrivateSave;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivateSave>
 */
class PrivateSaveFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'path' => 'private-saves/'.fake()->uuid().'.jpg',
            'width' => 1080,
            'height' => 1920,
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(10_000, 2_000_000),
        ];
    }
}
