<?php

namespace Tests\Feature;

use App\Models\DailyProductMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertForbidden();
    }

    public function test_admin_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_displays_product_metrics_and_partial_day_state(): void
    {
        DailyProductMetric::create([
            'metric_date' => today(),
            'daily_active_users' => 42,
            'registrations' => 5,
            'active_creators' => 7,
            'screenshots_published' => 11,
            'impressions' => 100,
            'opens' => 25,
            'saves' => 10,
            'follows' => 4,
            'hides' => 2,
            'reports' => 1,
            'is_partial' => true,
            'aggregated_at' => now(),
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Product overview')
            ->assertSee('Partial day')
            ->assertSee('42')
            ->assertSee('25.00%')
            ->assertSee('Moderation health');
    }
}
