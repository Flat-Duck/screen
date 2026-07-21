<?php

namespace Tests\Feature\Console;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RefreshRecommendationPoolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_atomically_publishes_versioned_pools_with_a_safety_ttl(): void
    {
        config(['social.recommendations.hot_pool_ttl_minutes' => 30]);
        $author = User::factory()->create(['country_code' => 'LY']);
        Post::factory()->for($author)->create();

        Redis::shouldReceive('del')->once()->with('recommendations:v1:hot:global:building');
        Redis::shouldReceive('zadd')->once()->with('recommendations:v1:hot:global:building', \Mockery::type('float'), \Mockery::type('int'));
        Redis::shouldReceive('rename')->once()->with('recommendations:v1:hot:global:building', 'recommendations:v1:hot:global');
        Redis::shouldReceive('expire')->once()->with('recommendations:v1:hot:global', 1800);
        Redis::shouldReceive('del')->once()->with('recommendations:v1:hot:country:ly:building');
        Redis::shouldReceive('zadd')->once()->with('recommendations:v1:hot:country:ly:building', \Mockery::type('float'), \Mockery::type('int'));
        Redis::shouldReceive('rename')->once()->with('recommendations:v1:hot:country:ly:building', 'recommendations:v1:hot:country:ly');
        Redis::shouldReceive('expire')->once()->with('recommendations:v1:hot:country:ly', 1800);

        $this->artisan('recommendations:refresh-pools')->assertSuccessful();
    }
}
