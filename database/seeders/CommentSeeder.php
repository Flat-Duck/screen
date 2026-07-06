<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;

class CommentSeeder extends Seeder
{
    /** Adds 0-3 comments per post from a random demo user (including the post's own author). */
    public function run(): void
    {
        $users = User::whereIn('username', UserSeeder::USERNAMES)->get();

        Post::all()->each(function (Post $post) use ($users) {
            for ($i = random_int(0, 3); $i > 0; $i--) {
                Comment::factory()->create([
                    'post_id' => $post->id,
                    'user_id' => $users->random()->id,
                ]);
            }
        });
    }
}
