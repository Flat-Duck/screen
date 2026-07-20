<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\ModerationCaseStatus;
use App\Livewire\ContentTable;
use App\Livewire\ModerationCaseDetail;
use App\Livewire\ModerationCasesTable;
use App\Models\ModerationCase;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Services\ModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ModerationCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_for_same_target_group_into_one_open_case(): void
    {
        $post = Post::factory()->create();
        $moderation = app(ModerationService::class);

        $first = $moderation->report(User::factory()->create(), 'post', $post->id, 'spam', null);
        $second = $moderation->report(User::factory()->create(), 'post', $post->id, 'harassment', 'Repeated abuse');

        $this->assertSame($first->moderation_case_id, $second->moderation_case_id);
        $case = ModerationCase::findOrFail($first->moderation_case_id);
        $this->assertSame(2, $case->report_count);
        $this->assertSame(ModerationCaseStatus::Open, $case->status);
    }

    public function test_moderation_pages_require_named_permission_and_disable_caching(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('moderation.cases.index'))->assertForbidden();

        $auditor = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::ReadOnlyAuditor]);
        $this->actingAs($auditor);
        $this->get(route('moderation.cases.index'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('moderation.content.index'))->assertOk();
    }

    public function test_case_assignment_note_and_resolution_are_audited(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $post = Post::factory()->create();
        $report = app(ModerationService::class)->report(User::factory()->create(), 'post', $post->id, 'spam', null);
        $case = ModerationCase::findOrFail($report->moderation_case_id);
        $this->actingAs($admin);

        Livewire::test(ModerationCaseDetail::class, ['case' => $case])
            ->set('reason', 'Taking ownership')
            ->call('assignToSelf')
            ->set('note', 'Checked report and author history.')
            ->call('addNote')
            ->set('reason', 'Report is valid and action completed')
            ->call('changeStatus', 'actioned');

        $case->refresh();
        $this->assertSame($admin->id, $case->assigned_to);
        $this->assertSame(ModerationCaseStatus::Actioned, $case->status);
        $this->assertNull($case->open_key);
        $this->assertSame(Report::STATUS_REVIEWED, $report->fresh()->status);
        $this->assertDatabaseCount('moderation_case_notes', 1);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'moderation_case.assigned', 'target_id' => $case->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'moderation_case.note_added', 'target_id' => $case->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'moderation_case.actioned', 'target_id' => $case->id]);
    }

    public function test_invalid_transition_and_missing_reason_do_not_create_audit_success(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $case = ModerationCase::create(['status' => ModerationCaseStatus::Actioned, 'report_count' => 1]);
        $this->actingAs($admin);

        Livewire::test(ModerationCaseDetail::class, ['case' => $case])
            ->set('reason', '')
            ->call('changeStatus', 'investigating')
            ->assertHasErrors('reason');

        $this->assertDatabaseCount('admin_audit_logs', 0);
        $this->assertSame(ModerationCaseStatus::Actioned, $case->fresh()->status);
    }

    public function test_content_action_can_remove_restore_and_exclude_post_from_recommendations(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $post = Post::factory()->create();
        $case = ModerationCase::create(['target_type' => Post::class, 'target_id' => $post->id, 'status' => 'open']);
        $this->actingAs($admin);

        Livewire::test(ModerationCaseDetail::class, ['case' => $case])
            ->set('reason', 'Unsafe for recommendations')
            ->call('setRecommendation', false)
            ->set('reason', 'Policy violating screenshot')
            ->call('removeContent');

        $this->assertFalse($post->fresh()->recommendation_eligible);
        $this->assertSoftDeleted($post);

        Livewire::test(ModerationCaseDetail::class, ['case' => $case])
            ->set('reason', 'Successful appeal')
            ->call('restorePost');
        $this->assertNotSoftDeleted($post);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'content.restored', 'target_id' => $post->id]);
    }

    public function test_private_and_soft_deleted_posts_remain_available_to_moderators(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $author = User::factory()->create(['account_visibility' => 'private']);
        $post = Post::factory()->for($author)->create(['caption' => 'Private moderation evidence']);
        $post->delete();
        $this->actingAs($admin);

        $this->get(route('moderation.content.show', $post->id))
            ->assertOk()
            ->assertSee('Private moderation evidence');
        Livewire::test(ContentTable::class)->set('state', 'removed')->assertSee('Private moderation evidence');
        Livewire::test(ModerationCasesTable::class)->assertSee('No moderation cases.');
    }
}
