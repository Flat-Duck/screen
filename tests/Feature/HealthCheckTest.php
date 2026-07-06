<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_deep_health_check_reports_healthy_under_normal_conditions(): void
    {
        Storage::fake('public');

        $response = $this->getJson('/up/deep');

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

        $response = $this->getJson('/up/deep');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'unhealthy');
        $response->assertJsonPath('checks.queue.ok', false);
    }
}
