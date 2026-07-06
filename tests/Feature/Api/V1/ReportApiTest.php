<?php

namespace Tests\Feature\Api\V1;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporting_a_post_succeeds(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reportable_type', 'post');
        $response->assertJsonPath('data.reason', 'spam');
        $response->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_reporting_a_comment_succeeds(): void
    {
        $comment = Comment::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/reports', [
            'reportable_type' => 'comment',
            'reportable_id' => $comment->id,
            'reason' => 'harassment',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reportable_type', 'comment');
    }

    public function test_reporting_a_user_succeeds(): void
    {
        $target = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/reports', [
            'reportable_type' => 'user',
            'reportable_id' => $target->id,
            'reason' => 'other',
            'details' => 'Suspicious activity',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reportable_type', 'user');
    }

    public function test_reporting_the_same_target_twice_is_idempotent(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
        ])->assertCreated();

        $response = $this->postJson('/api/v1/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_reporting_an_invalid_reportable_type_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/reports', [
            'reportable_type' => 'group',
            'reportable_id' => 1,
            'reason' => 'spam',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['reportable_type']);
    }

    public function test_reporting_an_unknown_target_id_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/reports', [
            'reportable_type' => 'post',
            'reportable_id' => 999999,
            'reason' => 'spam',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['reportable_id']);
    }

    public function test_reporting_yourself_as_a_user_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/reports', [
            'reportable_type' => 'user',
            'reportable_id' => $user->id,
            'reason' => 'other',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['reportable_id']);
    }
}
