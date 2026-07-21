<?php

namespace Tests\Feature;

use App\Models\ContentEvent;
use App\Models\CrashGroup;
use App\Models\DailyProductMetric;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Scopes\NotArchivedScope;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_create_a_complete_remote_media_simulation_dataset(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(28, User::query()->count());
        $this->assertSame(240, Post::withoutGlobalScope(NotArchivedScope::class)->withTrashed()->count());
        $this->assertGreaterThanOrEqual(240, PostMedia::query()->count());
        $this->assertSame(0, PostMedia::query()->where('original_path', 'not like', 'https://picsum.photos/%')->count());
        $this->assertGreaterThan(500, ContentEvent::query()->count());
        $this->assertSame(30, DailyProductMetric::query()->count());
        $this->assertGreaterThanOrEqual(5, CrashGroup::query()->count());
        $this->assertDatabaseCount('collections', 72);
        $this->assertDatabaseCount('operations_health_snapshots', 1);
        $alice = User::query()->where('username', 'alice')->firstOrFail();

        foreach (['flutter', 'dart', 'mobiledev', 'android'] as $tag) {
            $count = Post::withoutGlobalScope(NotArchivedScope::class)
                ->withTrashed()
                ->where('user_id', $alice->id)
                ->whereHas('hashtags', fn ($query) => $query->where('name', $tag))
                ->count();
            $this->assertGreaterThanOrEqual(8, $count);
        }

        $this->assertDatabaseCount('device_push_tokens', 12);
        $this->assertDatabaseCount('social_accounts', 8);
        $this->assertDatabaseCount('notifications', 12);
        $this->assertDatabaseCount('mentions', 12);
        $this->assertDatabaseCount('user_hidden_terms', 6);
        $this->assertDatabaseCount('user_restrictions', 5);
        $this->assertDatabaseCount('recommendation_feed_sessions', 10);
        $this->assertDatabaseCount('recommendation_exclusions', 4);
    }
}
