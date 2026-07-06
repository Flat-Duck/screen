<?php

namespace Database\Seeders;

use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;

class LikeSeeder extends Seeder
{
    /** Has a random subset of the other demo users like each post. */
    public function run(): void
    {
        $users = User::whereIn('username', UserSeeder::USERNAMES)->get();

        Post::all()->each(function (Post $post) use ($users) {
            $others = $users->reject(fn (User $user) => $user->id === $post->user_id)->values();

            $likerCount = random_int(0, $others->count());

            $others->random($likerCount)->each(fn (User $user) => Like::firstOrCreate([
                'post_id' => $post->id,
                'user_id' => $user->id,
            ]));
        });
    }
}
