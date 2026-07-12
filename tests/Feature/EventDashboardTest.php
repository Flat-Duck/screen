<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\TelemetryEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $event = TelemetryEvent::factory()->for(Device::factory())->create();

        $this->get(route('events.index'))->assertRedirect(route('login'));
        $this->get(route('events.show', $event))->assertRedirect(route('login'));
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create());
        $event = TelemetryEvent::factory()->for(Device::factory())->create();

        $this->get(route('events.index'))->assertForbidden();
        $this->get(route('events.show', $event))->assertForbidden();
    }

    public function test_admin_users_can_view_the_events_list(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        TelemetryEvent::factory()->for(Device::factory())->count(3)->create();

        $this->get(route('events.index'))->assertOk();
    }

    public function test_event_show_page_displays_breadcrumbs_and_stack_trace_for_a_crash(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $device = Device::factory()->create();
        $crash = TelemetryEvent::factory()->for($device)->fatalCrash()->create([
            'breadcrumbs' => [
                ['ts' => now()->toIso8601String(), 'type' => 'event', 'name' => 'permission_request_result', 'extras' => []],
            ],
        ]);

        $response = $this->get(route('events.show', $crash));

        $response->assertOk();
        $response->assertSee('java.lang.IllegalStateException');
        $response->assertSee('permission_request_result');
    }
}
