<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class FollowSeeder extends Seeder
{
    /** Creates a dense but non-complete graph so discovery still has useful two-hop candidates. */
    public function run(): void
    {
        $users = User::whereIn('username', UserSeeder::USERNAMES)->get();

        $users->each(function (User $follower) use ($users) {
            $candidates = $users->reject(fn (User $user) => $user->is($follower))->shuffle()->values();
            $followeeIds = $candidates->take(random_int(1, max(1, min(16, $candidates->count()))))->pluck('id');

            $follower->following()->syncWithoutDetaching($followeeIds);
        });
    }
}
