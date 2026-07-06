<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Usernames of the seeded demo users, shared with FollowSeeder/PostSeeder/etc.
     * so they can pull the same set back out without re-declaring it.
     *
     * @var array<int, string>
     */
    public const USERNAMES = ['alice', 'bob', 'carol'];

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
        ]);

        collect([
            ['name' => 'Alice Nakamura', 'username' => 'alice', 'email' => 'alice@example.com','password' => bcrypt('123123')],
            ['name' => 'Bob Sanderson', 'username' => 'bob', 'email' => 'bob@example.com','password'=> bcrypt('123123')],
            ['name' => 'Carol Vasquez', 'username' => 'carol', 'email' => 'carol@example.com','password'=> bcrypt('123123')],
        ])->each(fn (array $attributes) => User::factory()->create([
            ...$attributes,
            'bio' => fake()->sentence(),
        ]));
    }
}
