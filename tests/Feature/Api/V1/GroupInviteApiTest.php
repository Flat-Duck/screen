<?php

namespace Tests\Feature\Api\V1;

use App\Enums\GroupInviteStatus;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupInviteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_admin_can_invite_a_user(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);
        $group = $this->createGroup($admin, 'Photography');
        $invitee = User::factory()->create();

        $this->postJson("/api/v1/groups/{$group->id}/invites", ['user_id' => $invitee->id])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.group.id', $group->id)
            ->assertJsonPath('data.inviter.id', $admin->id);

        $this->assertDatabaseHas('group_invites', [
            'group_id' => $group->id,
            'inviter_id' => $admin->id,
            'invitee_id' => $invitee->id,
            'status' => GroupInviteStatus::Pending->value,
        ]);
    }

    public function test_non_admin_member_cannot_invite(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);
        $group = $this->createGroup($admin, 'Gaming');

        $member = User::factory()->create();
        Sanctum::actingAs($member);
        $this->postJson("/api/v1/groups/{$group->id}/membership")->assertNoContent();

        $invitee = User::factory()->create();
        $this->postJson("/api/v1/groups/{$group->id}/invites", ['user_id' => $invitee->id])->assertForbidden();
    }

    public function test_inviting_an_existing_member_fails_validation(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);
        $group = $this->createGroup($admin, 'Design');

        $this->postJson("/api/v1/groups/{$group->id}/invites", ['user_id' => $admin->id])
            ->assertStatus(422);
    }

    public function test_invitee_can_list_accept_and_decline_invites(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);
        $group = $this->createGroup($admin, 'Memes');

        $invitee = User::factory()->create();
        $this->postJson("/api/v1/groups/{$group->id}/invites", ['user_id' => $invitee->id])->assertStatus(202);

        Sanctum::actingAs($invitee);
        $incoming = $this->getJson('/api/v1/group-invites/incoming')->assertOk();
        $incoming->assertJsonCount(1, 'data')->assertJsonPath('data.0.group.id', $group->id);
        $inviteId = $incoming->json('data.0.id');

        $this->postJson("/api/v1/group-invites/{$inviteId}/accept")->assertNoContent();
        $this->assertDatabaseHas('group_invites', ['id' => $inviteId, 'status' => GroupInviteStatus::Accepted->value]);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $invitee->id, 'role' => 'member']);
        $this->assertSame(2, $group->fresh()->member_count);
        $this->getJson('/api/v1/group-invites/incoming')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_declining_an_invite_does_not_add_membership(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);
        $group = $this->createGroup($admin, 'Travel');

        $invitee = User::factory()->create();
        $invite = GroupInvite::query()->create([
            'group_id' => $group->id,
            'inviter_id' => $admin->id,
            'invitee_id' => $invitee->id,
            'status' => GroupInviteStatus::Pending,
        ]);

        Sanctum::actingAs($invitee);
        $this->postJson("/api/v1/group-invites/{$invite->id}/decline")->assertNoContent();

        $this->assertDatabaseHas('group_invites', ['id' => $invite->id, 'status' => GroupInviteStatus::Declined->value]);
        $this->assertDatabaseMissing('group_members', ['group_id' => $group->id, 'user_id' => $invitee->id]);
        $this->assertSame(1, $group->fresh()->member_count);
    }

    public function test_only_the_invitee_can_respond_to_an_invite(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);
        $group = $this->createGroup($admin, 'Books');

        $invitee = User::factory()->create();
        $invite = GroupInvite::query()->create([
            'group_id' => $group->id,
            'inviter_id' => $admin->id,
            'invitee_id' => $invitee->id,
            'status' => GroupInviteStatus::Pending,
        ]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/group-invites/{$invite->id}/accept")->assertNotFound();
    }

    public function test_accepting_an_invite_to_a_private_group_still_joins(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);
        $group = $this->createGroup($admin, 'Inner Circle', visibility: 'private');

        $invitee = User::factory()->create();
        $this->postJson("/api/v1/groups/{$group->id}/invites", ['user_id' => $invitee->id])->assertStatus(202);

        Sanctum::actingAs($invitee);
        $inviteId = $this->getJson('/api/v1/group-invites/incoming')->json('data.0.id');

        $this->postJson("/api/v1/group-invites/{$inviteId}/accept")->assertNoContent();
        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $invitee->id, 'role' => 'member']);
    }

    private function createGroup(User $creator, string $name, string $visibility = 'public'): Group
    {
        $group = Group::create(['creator_id' => $creator->id, 'name' => $name, 'visibility' => $visibility, 'member_count' => 1]);
        $group->members()->create(['user_id' => $creator->id, 'role' => 'admin']);

        return $group;
    }
}
