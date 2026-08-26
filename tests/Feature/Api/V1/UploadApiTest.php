<?php

namespace Tests\Feature\Api\V1;

use App\Models\Upload;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class UploadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_returns_a_presigned_upload_url_and_creates_an_uploading_row(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('temporaryUploadUrl')
            ->once()
            ->andReturn(['url' => 'https://r2.example.com/signed-put', 'headers' => ['Content-Type' => 'image/jpeg']]);
        Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

        $response = $this->postJson('/api/v1/uploads/prepare', [
            'content_type' => 'image/jpeg',
            'byte_size' => 123_456,
        ]);

        $response->assertCreated()
            ->assertJsonPath('upload_url', 'https://r2.example.com/signed-put')
            ->assertJsonPath('headers.Content-Type', 'image/jpeg')
            ->assertJsonPath('data.status', Upload::STATUS_UPLOADING);
        $this->assertDatabaseHas('uploads', [
            'user_id' => $user->id,
            'status' => Upload::STATUS_UPLOADING,
            'size_bytes' => 123_456,
        ]);
    }

    public function test_prepare_rejects_a_disallowed_content_type(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/uploads/prepare', [
            'content_type' => 'application/pdf',
            'byte_size' => 100,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('content_type');
    }

    public function test_commit_marks_the_upload_uploaded_when_the_object_exists_with_matching_size(): void
    {
        Storage::fake('r2');
        Sanctum::actingAs($user = User::factory()->create());
        $upload = Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'object_key' => 'screenshots/1/abc.jpg',
            'nonce' => 'test-nonce',
            'size_bytes' => 4,
            'status' => Upload::STATUS_UPLOADING,
            'expires_at' => now()->addMinutes(10),
        ]);
        Storage::disk('r2')->put('screenshots/1/abc.jpg', 'fake');

        $response = $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'image_sha256' => str_repeat('a', 64),
            'ocr_text' => 'hello world',
        ]);

        $response->assertOk()->assertJsonPath('data.status', Upload::STATUS_UPLOADED);
        $this->assertSame(Upload::STATUS_UPLOADED, $upload->fresh()->status);
        $this->assertSame('hello world', $upload->fresh()->ocr_text);
    }

    public function test_commit_rejects_when_the_object_never_landed_in_r2(): void
    {
        Storage::fake('r2');
        Sanctum::actingAs($user = User::factory()->create());
        $upload = Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'object_key' => 'screenshots/1/missing.jpg',
            'nonce' => 'test-nonce-2',
            'status' => Upload::STATUS_UPLOADING,
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'image_sha256' => str_repeat('b', 64),
        ]);

        $response->assertUnprocessable();
        $this->assertSame(Upload::STATUS_REJECTED, $upload->fresh()->status);
    }

    public function test_commit_rejects_a_size_mismatch_against_what_was_declared_at_prepare_time(): void
    {
        Storage::fake('r2');
        Sanctum::actingAs($user = User::factory()->create());
        $upload = Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'object_key' => 'screenshots/1/swapped.jpg',
            'nonce' => 'test-nonce-3',
            'size_bytes' => 999_999,
            'status' => Upload::STATUS_UPLOADING,
            'expires_at' => now()->addMinutes(10),
        ]);
        Storage::disk('r2')->put('screenshots/1/swapped.jpg', 'a-much-smaller-body');

        $response = $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'image_sha256' => str_repeat('c', 64),
        ]);

        $response->assertUnprocessable();
        $this->assertSame(Upload::STATUS_REJECTED, $upload->fresh()->status);
    }

    public function test_commit_is_not_allowed_for_someone_elses_upload(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $upload = Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => User::factory()->create()->id,
            'object_key' => 'screenshots/2/other.jpg',
            'nonce' => 'test-nonce-4',
            'status' => Upload::STATUS_UPLOADING,
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'image_sha256' => str_repeat('d', 64),
        ]);

        $response->assertNotFound();
    }

    public function test_commit_rejects_a_second_attempt_on_an_already_committed_upload(): void
    {
        Storage::fake('r2');
        Sanctum::actingAs($user = User::factory()->create());
        $upload = Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'object_key' => 'screenshots/1/twice.jpg',
            'nonce' => 'test-nonce-5',
            'size_bytes' => 4,
            'status' => Upload::STATUS_UPLOADED,
            'expires_at' => now()->addMinutes(10),
        ]);
        Storage::disk('r2')->put('screenshots/1/twice.jpg', 'fake');

        $response = $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'image_sha256' => str_repeat('e', 64),
        ]);

        $response->assertStatus(409);
    }
}
