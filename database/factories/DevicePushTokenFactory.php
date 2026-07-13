<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DevicePushToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DevicePushToken>
 */
class DevicePushTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'fcm_token' => fake()->uuid(),
            'platform' => 'android',
        ];
    }
}
