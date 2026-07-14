<?php

namespace Tests\Feature;

use App\Livewire\UsersTable;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UsersTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('users.index'))->assertForbidden();
    }

    public function test_admin_users_can_view_the_page(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        User::factory()->create(['username' => 'someone']);

        $response = $this->get(route('users.index'));

        $response->assertOk();
        $response->assertSee('someone');
    }

    public function test_search_filters_by_username_name_or_email(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        User::factory()->create(['username' => 'findme', 'name' => 'Someone', 'email' => 'a@example.com']);
        User::factory()->create(['username' => 'other', 'name' => 'Nobody', 'email' => 'b@example.com']);

        Livewire::test(UsersTable::class)
            ->set('search', 'findme')
            ->assertSee('findme')
            ->assertDontSee('other');
    }

    public function test_creating_a_user(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(UsersTable::class)
            ->set('newName', 'Jane Doe')
            ->set('newUsername', 'janedoe')
            ->set('newEmail', 'jane@example.com')
            ->set('newPassword', 'password1234')
            ->set('newPasswordConfirmation', 'password1234')
            ->call('createUser')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['username' => 'janedoe', 'email' => 'jane@example.com']);
    }

    public function test_creating_a_user_requires_matching_password_confirmation(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(UsersTable::class)
            ->set('newName', 'Jane Doe')
            ->set('newUsername', 'janedoe')
            ->set('newEmail', 'jane@example.com')
            ->set('newPassword', 'password1234')
            ->set('newPasswordConfirmation', 'different')
            ->call('createUser')
            ->assertHasErrors(['newPasswordConfirmation']);
    }

    public function test_creating_a_user_rejects_a_duplicate_username(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        User::factory()->create(['username' => 'taken']);

        Livewire::test(UsersTable::class)
            ->set('newName', 'Jane Doe')
            ->set('newUsername', 'taken')
            ->set('newEmail', 'jane@example.com')
            ->set('newPassword', 'password1234')
            ->set('newPasswordConfirmation', 'password1234')
            ->call('createUser')
            ->assertHasErrors(['newUsername']);
    }

    public function test_editing_a_user(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        // Faker's userName() can include dots ("first.last12"), which the same alpha_dash
        // rule every other username-editing surface in this app already enforces
        // (UpdateProfileRequest, RegisterUserRequest) would reject — use a conforming
        // fixture value so this test exercises the edit itself, not that pre-existing rule.
        $target = User::factory()->create(['name' => 'Old Name', 'username' => 'old-username']);

        Livewire::test(UsersTable::class)
            ->call('startEdit', $target->id)
            ->assertSet('editName', 'Old Name')
            ->set('editName', 'New Name')
            ->set('editUsername', $target->username)
            ->set('editEmail', $target->email)
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('editingUserId', null);

        $this->assertSame('New Name', $target->fresh()->name);
    }

    public function test_toggling_active_deactivates_and_revokes_sessions(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $target = User::factory()->create();
        $target->createToken('mobile');

        Livewire::test(UsersTable::class)->call('toggleActive', $target->id);

        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_deactivated_users_cannot_log_in(): void
    {
        $target = User::factory()->create(['password' => 'password123!', 'username' => 'blocked']);
        $target->is_active = false;
        $target->save();

        $device = Device::factory()->create();
        $deviceToken = $device->createToken('device', ['device:manage'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$deviceToken}")
            ->postJson('/api/v1/auth/login', ['login' => 'blocked', 'password' => 'password123!']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['account']);
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(UsersTable::class)->call('toggleActive', $admin->id);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_toggling_admin_access(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $target = User::factory()->create();

        Livewire::test(UsersTable::class)->call('toggleAdmin', $target->id);

        $this->assertTrue($target->fresh()->is_admin);
    }

    public function test_an_admin_cannot_remove_their_own_admin_access(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(UsersTable::class)->call('toggleAdmin', $admin->id);

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_deleting_a_user(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $target = User::factory()->create();

        Livewire::test(UsersTable::class)->call('deleteUser', $target->id);

        $this->assertSoftDeleted($target);
    }

    public function test_an_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(UsersTable::class)->call('deleteUser', $admin->id);

        $this->assertNotSoftDeleted($admin);
    }

    public function test_restoring_a_deleted_user(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $target = User::factory()->create();
        $target->delete();

        Livewire::test(UsersTable::class)->call('restoreUser', $target->id);

        $this->assertNotSoftDeleted($target->fresh());
    }

    public function test_show_deleted_filter_lists_only_trashed_users(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $active = User::factory()->create(['username' => 'stillhere']);
        $deleted = User::factory()->create(['username' => 'goneuser']);
        $deleted->delete();

        Livewire::test(UsersTable::class)
            ->set('showDeleted', true)
            ->assertSee('goneuser')
            ->assertDontSee('stillhere');
    }
}
