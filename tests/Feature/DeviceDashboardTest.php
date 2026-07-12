<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\TelemetryEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $device = Device::factory()->create();

        $this->get(route('devices.index'))->assertRedirect(route('login'));
        $this->get(route('devices.show', $device))->assertRedirect(route('login'));
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();

        $this->get(route('devices.index'))->assertForbidden();
        $this->get(route('devices.show', $device))->assertForbidden();
    }

    public function test_admin_users_can_view_the_devices_list(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Device::factory()->count(3)->create();

        $this->get(route('devices.index'))->assertOk();
    }

    public function test_device_show_page_lists_its_events_and_crashes(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $device = Device::factory()->create();
        TelemetryEvent::factory()->for($device)->create(['name' => 'screenshot_detected']);
        TelemetryEvent::factory()->for($device)->fatalCrash()->create();

        $response = $this->get(route('devices.show', $device));

        $response->assertOk();
        $response->assertSee('screenshot_detected');
        $response->assertSee('fatal_crash');
    }
}
