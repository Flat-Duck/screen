<?php

namespace Database\Factories;

use App\Enums\LoginMethod;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeviceSession> */
class DeviceSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'device_id' => Device::factory(),
            'user_id' => User::factory(),
            'login_method' => LoginMethod::Password,
            'started_at' => now(),
            'last_seen_at' => now(),
            'app_version_name' => '1.0',
            'app_version_code' => 1,
            'os_version' => '14',
        ];
    }
}
