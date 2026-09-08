<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A 422 on the API used to leave no trace anywhere: Laravel does not report ValidationException,
 * nginx's access log is root-only on the production host, and the Android client renders the
 * failure as a bare `ApiException` carrying no message. A failing upload was therefore
 * undiagnosable from the server side.
 */
class ApiValidationLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rejected_api_request_logs_the_failing_field(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($user): bool {
                return $message === 'API validation failed'
                    && $context['method'] === 'POST'
                    && $context['route'] === 'api/v1/private-saves'
                    && $context['user_id'] === $user->id
                    && array_key_exists('image', $context['errors']);
            });

        // No `image` at all — the exact shape a truncated or dropped multipart upload produces.
        $this->postJson('/api/v1/private-saves', [])->assertStatus(422);
    }

    /** The values are user content — screenshots, post bodies, passwords on the auth routes — so
     * the context must carry field names and framework messages and nothing else. */
    public function test_the_context_carries_no_submitted_values(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($message, $context) use (&$logged): void {
            $logged[] = $context;
        });

        $this->postJson('/api/v1/private-saves', [
            'image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            'folder_id' => 987654321,
        ])->assertStatus(422);

        $this->assertCount(1, $logged);
        $context = $logged[0];
        $this->assertSame(['method', 'route', 'user_id', 'errors'], array_keys($context));
        // Every leaf under `errors` is a framework message keyed by field name, never an echo of
        // what was sent — so a rejected password or a screenshot filename cannot reach the log.
        foreach ($context['errors'] as $field => $messages) {
            $this->assertIsString($field);
            foreach ($messages as $message) {
                $this->assertIsString($message);
            }
        }
        $this->assertStringNotContainsString('987654321', (string) json_encode($context));
        $this->assertStringNotContainsString('notes.txt', (string) json_encode($context));
    }

    public function test_a_successful_request_logs_nothing(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Log::shouldReceive('warning')->never();

        $this->postJson('/api/v1/private-saves', [
            'image' => UploadedFile::fake()->image('screen.png', 400, 800),
        ])->assertCreated();
    }
}
