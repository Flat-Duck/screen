<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
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
            'provider' => fake()->randomElement([
                SocialAccount::PROVIDER_GOOGLE,
                SocialAccount::PROVIDER_FACEBOOK,
                SocialAccount::PROVIDER_APPLE,
            ]),
            'provider_user_id' => fake()->uuid(),
            'avatar_url' => fake()->optional()->imageUrl(),
        ];
    }
}
