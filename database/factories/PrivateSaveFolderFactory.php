<?php

namespace Database\Factories;

use App\Models\PrivateSaveFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivateSaveFolder>
 */
class PrivateSaveFolderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'user_id' => User::factory(),
            'slug' => str($name)->slug()->toString(),
            'name' => str($name)->title()->toString(),
            'is_default' => false,
            'position' => fake()->numberBetween(3, 20),
        ];
    }
}
