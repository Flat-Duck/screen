<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\CrashGroupStatus;
use App\Livewire\CrashGroupDetail;
use App\Livewire\CrashGroupsTable;
use App\Models\CrashGroup;
use App\Models\Device;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Services\CrashGroupSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrashTriageTest extends TestCase
{
    use RefreshDatabase;

    public function test_crashes_with_the_same_fingerprint_form_one_group_and_count_unique_users(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $synchronizer = app(CrashGroupSynchronizer::class);
        foreach ([$firstUser, $firstUser, $secondUser] as $index => $user) {
            $event = TelemetryEvent::factory()->fatalCrash()->create([
                'user_id' => $user->id, 'crash_fingerprint' => str_repeat('a', 64),
                'occurred_at' => now()->addMinutes($index), 'app_version_name' => '3.2.0', 'os_version' => '15',
            ]);
            $synchronizer->sync($event);
            $this->assertNotNull($event->fresh()->crash_group_id);
        }

        $group = CrashGroup::query()->firstOrFail();
        $this->assertSame(3, $group->occurrence_count);
        $this->assertSame(2, $group->affected_user_count);
        $this->assertDatabaseCount('crash_group_users', 2);
    }

    public function test_crash_pages_require_telemetry_permission_and_disable_detail_caching(): void
    {
        $group = $this->group();
        $this->actingAs(User::factory()->create())->get(route('crash-groups.index'))->assertForbidden();

        $viewer = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::TelemetryViewer]);
        $this->actingAs($viewer)->get(route('crash-groups.index'))->assertOk()->assertSee('Crash triage');
        $this->get(route('crash-groups.show', $group))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
    }

    public function test_group_filters_cover_release_os_and_device(): void
    {
        $group = $this->group();
        $device = Device::factory()->create(['manufacturer' => 'Google', 'model' => 'Pixel 10']);
        TelemetryEvent::factory()->fatalCrash()->for($device)->create([
            'crash_group_id' => $group->id, 'crash_fingerprint' => $group->fingerprint,
            'app_version_name' => '9.1.0', 'app_version_code' => 91, 'os_version' => '16',
        ]);
        $viewer = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::TelemetryViewer]);
        $this->actingAs($viewer);

        Livewire::test(CrashGroupsTable::class)->set('release', '9.1.0')->set('os', '16')->set('device', 'Pixel')->assertSee($group->name);
        Livewire::test(CrashGroupsTable::class)->set('release', 'missing')->assertDontSee($group->name);
    }

    public function test_super_admin_can_assign_note_resolve_and_reopen_with_audit_history(): void
    {
        $group = $this->group();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::SuperAdmin]);
        $this->actingAs($admin);

        Livewire::test(CrashGroupDetail::class, ['group' => $group])
            ->set('reason', 'Investigating production regression')->call('assignToSelf')
            ->set('note', 'Reproduced on Android 15 with release 9.1.0.')->call('addNote')
            ->set('reason', 'Fixed by null-state guard')->set('fixedVersion', '9.1.1')->call('changeStatus', 'resolved');

        $group->refresh();
        $this->assertSame(CrashGroupStatus::Resolved, $group->status);
        $this->assertSame('9.1.1', $group->fixed_app_version);
        $this->assertSame($admin->id, $group->assigned_to);
        $this->assertDatabaseCount('crash_group_notes', 1);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'crash_group.assigned', 'target_id' => $group->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'crash_group.note_added', 'target_id' => $group->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'crash_group.resolved', 'target_id' => $group->id]);

        Livewire::test(CrashGroupDetail::class, ['group' => $group])->set('reason', 'Regression returned')->call('changeStatus', 'open');
        $this->assertSame(CrashGroupStatus::Open, $group->fresh()->status);
        $this->assertNull($group->fresh()->fixed_app_version);
    }

    public function test_telemetry_viewer_cannot_mutate_triage_state(): void
    {
        $group = $this->group();
        $viewer = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::TelemetryViewer]);
        $this->actingAs($viewer);

        Livewire::test(CrashGroupDetail::class, ['group' => $group])->set('reason', 'Trying mutation')->call('assignToSelf')->assertForbidden();
        $this->assertNull($group->fresh()->assigned_to);
    }

    private function group(): CrashGroup
    {
        return CrashGroup::query()->create([
            'fingerprint' => str_repeat('b', 64), 'name' => 'fatal_crash',
            'exception_class' => 'java.lang.IllegalStateException',
            'first_seen_at' => now()->subHour(), 'last_seen_at' => now(),
        ]);
    }
}
