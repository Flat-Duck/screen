<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_uuid' => $this->faker->uuid(),
            'manufacturer' => $this->faker->randomElement(['Google', 'Samsung', 'Xiaomi']),
            'brand' => $this->faker->randomElement(['google', 'samsung', 'xiaomi']),
            'model' => $this->faker->randomElement(['Pixel 8', 'Galaxy S24', 'Redmi Note 13']),
            'os_name' => 'Android',
            'os_version' => $this->faker->randomElement(['12', '13', '14']),
            'sdk_int' => $this->faker->randomElement([31, 33, 34]),
            'app_version_name' => '1.0',
            'app_version_code' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
