<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;

class CommentSeeder extends Seeder
{
    /** Adds discussion depth, including one-level replies, for API and dashboard pagination. */
    public function run(): void
    {
        $users = User::whereIn('username', UserSeeder::USERNAMES)->get();

        Post::all()->each(function (Post $post) use ($users) {
            for ($i = random_int(1, 5); $i > 0; $i--) {
                $comment = Comment::factory()->create([
                    'post_id' => $post->id,
                    'user_id' => $users->random()->id,
                ]);
                if (random_int(1, 100) <= 35) {
                    Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $comment->id, 'user_id' => $users->random()->id]);
                }
            }
        });
    }
}
