<?php

namespace Tests\Feature;

use App\Livewire\ReportsTable;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('reports.index'))->assertForbidden();
    }

    public function test_admin_users_can_view_the_page(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $post = Post::factory()->create();
        Report::create([
            'reporter_id' => User::factory()->create()->id,
            'reportable_type' => Post::class,
            'reportable_id' => $post->id,
            'reason' => 'spam',
            'status' => Report::STATUS_PENDING,
        ]);

        $response = $this->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('Spam');
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $reporter = User::factory()->create();
        $pending = Report::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => User::class,
            'reportable_id' => User::factory()->create()->id,
            'reason' => 'spam',
            'status' => Report::STATUS_PENDING,
        ]);
        Report::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => User::class,
            'reportable_id' => User::factory()->create()->id,
            'reason' => 'other',
            'status' => Report::STATUS_DISMISSED,
        ]);

        Livewire::test(ReportsTable::class)
            ->set('status', Report::STATUS_PENDING)
            ->assertSee('Spam')
            ->assertDontSee('Other');
    }

    public function test_marking_a_report_reviewed(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $report = Report::create([
            'reporter_id' => User::factory()->create()->id,
            'reportable_type' => User::class,
            'reportable_id' => User::factory()->create()->id,
            'reason' => 'spam',
            'status' => Report::STATUS_PENDING,
        ]);

        Livewire::test(ReportsTable::class)->call('markReviewed', $report->id);

        $report->refresh();
        $this->assertSame(Report::STATUS_REVIEWED, $report->status);
        $this->assertTrue($admin->is($report->reviewedBy));
        $this->assertNotNull($report->reviewed_at);
    }

    public function test_dismissing_a_report(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $report = Report::create([
            'reporter_id' => User::factory()->create()->id,
            'reportable_type' => User::class,
            'reportable_id' => User::factory()->create()->id,
            'reason' => 'other',
            'status' => Report::STATUS_PENDING,
        ]);

        Livewire::test(ReportsTable::class)->call('dismiss', $report->id);

        $this->assertSame(Report::STATUS_DISMISSED, $report->fresh()->status);
    }

    public function test_removing_reported_post_content_deletes_the_post_and_marks_reviewed(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $post = Post::factory()->create();
        $report = Report::create([
            'reporter_id' => User::factory()->create()->id,
            'reportable_type' => Post::class,
            'reportable_id' => $post->id,
            'reason' => 'nudity',
            'status' => Report::STATUS_PENDING,
        ]);

        Livewire::test(ReportsTable::class)->call('removeContent', $report->id);

        $this->assertSoftDeleted($post);
        $this->assertSame(Report::STATUS_REVIEWED, $report->fresh()->status);
    }

    public function test_removing_reported_comment_content_deletes_the_comment(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $comment = Comment::factory()->create();
        $report = Report::create([
            'reporter_id' => User::factory()->create()->id,
            'reportable_type' => Comment::class,
            'reportable_id' => $comment->id,
            'reason' => 'harassment',
            'status' => Report::STATUS_PENDING,
        ]);

        Livewire::test(ReportsTable::class)->call('removeContent', $report->id);

        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    public function test_suspending_the_author_of_a_reported_post(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $author = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        $report = Report::create([
            'reporter_id' => User::factory()->create()->id,
            'reportable_type' => Post::class,
            'reportable_id' => $post->id,
            'reason' => 'harassment',
            'status' => Report::STATUS_PENDING,
        ]);

        Livewire::test(ReportsTable::class)->call('suspendAuthor', $report->id);

        $this->assertFalse($author->fresh()->is_active);
        $this->assertSame(Report::STATUS_REVIEWED, $report->fresh()->status);
    }

    public function test_an_admin_cannot_suspend_themselves_via_a_self_report(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $report = Report::create([
            'reporter_id' => User::factory()->create()->id,
            'reportable_type' => User::class,
            'reportable_id' => $admin->id,
            'reason' => 'other',
            'status' => Report::STATUS_PENDING,
        ]);

        Livewire::test(ReportsTable::class)->call('suspendAuthor', $report->id);

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame(Report::STATUS_PENDING, $report->fresh()->status);
    }
}
