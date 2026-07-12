<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function withSecret(): self
    {
        return $this->withHeader('X-Health-Check-Secret', config('health.deep_check_secret'));
    }

    public function test_deep_health_check_reports_healthy_under_normal_conditions(): void
    {
        Storage::fake('public');

        $response = $this->withSecret()->getJson('/up/deep');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checks.database.ok', true);
        $response->assertJsonPath('checks.queue.ok', true);
        $response->assertJsonPath('checks.storage.ok', true);
    }

    public function test_deep_health_check_reports_unhealthy_when_the_job_queue_backs_up(): void
    {
        Storage::fake('public');

        DB::table('jobs')->insert(array_fill(0, 501, [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'created_at' => now()->timestamp,
            'available_at' => now()->timestamp,
        ]));

        $response = $this->withSecret()->getJson('/up/deep');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'unhealthy');
        $response->assertJsonPath('checks.queue.ok', false);
    }

    public function test_deep_health_check_404s_without_the_secret(): void
    {
        $this->getJson('/up/deep')->assertNotFound();
    }

    public function test_deep_health_check_404s_with_the_wrong_secret(): void
    {
        $this->withHeader('X-Health-Check-Secret', 'wrong')
            ->getJson('/up/deep')
            ->assertNotFound();
    }

    public function test_deep_health_check_fails_closed_when_the_secret_is_unconfigured(): void
    {
        config(['health.deep_check_secret' => null]);

        $this->withHeader('X-Health-Check-Secret', '')
            ->getJson('/up/deep')
            ->assertNotFound();
    }
}
