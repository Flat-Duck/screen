<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Device;
use App\Models\OperationsHealthSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_authorized_operational_roles_can_view_the_dashboard(): void
    {
        $moderator = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $this->actingAs($moderator)->get(route('operations.index'))->assertForbidden();

        $viewer = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::TelemetryViewer]);
        $this->actingAs($viewer)->get(route('operations.index'))->assertOk()->assertSee('Operations health');
    }

    public function test_health_capture_persists_bounded_dependency_and_workflow_metrics(): void
    {
        Storage::fake('public');
        Device::factory()->create(['app_version_name' => '2.4.0', 'last_seen_at' => now()]);

        $this->artisan('operations:capture-health')->assertSuccessful();

        $snapshot = OperationsHealthSnapshot::query()->firstOrFail();
        $this->assertSame('healthy', $snapshot->status);
        $this->assertSame('ok', $snapshot->checks['database']['status']);
        $this->assertSame('ok', $snapshot->checks['storage']['status']);
        $this->assertSame('2.4.0', $snapshot->metrics['app_versions'][0]['version']);
        $this->assertArrayHasKey('queue_backlog', $snapshot->metrics);
        $this->assertArrayHasKey('security_outbox_backlog', $snapshot->metrics);
    }

    public function test_api_requests_are_aggregated_without_storing_request_content(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/search/users?q=screen')->assertOk();

        $this->assertDatabaseHas('api_request_metrics', ['requests' => 1, 'errors' => 0]);
        $this->assertDatabaseCount('api_request_metrics', 1);
    }
}
