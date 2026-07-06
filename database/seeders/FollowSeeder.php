<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class FollowSeeder extends Seeder
{
    /** Makes the demo users follow each other — full graph, no self-follows. */
    public function run(): void
    {
        $users = User::whereIn('username', UserSeeder::USERNAMES)->get();

        $users->each(function (User $follower) use ($users) {
            $followeeIds = $users->reject(fn (User $user) => $user->is($follower))->pluck('id');

            $follower->following()->syncWithoutDetaching($followeeIds);
        });
    }
}
