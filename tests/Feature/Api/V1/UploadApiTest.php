<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class UploadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_returns_a_presigned_upload_url_and_creates_an_uploading_row(): void
    {
        $device = $this->authenticateUploadUser($user = User::factory()->create());
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('temporaryUploadUrl')
            ->once()
            ->andReturn(['url' => 'https://r2.example.com/signed-put', 'headers' => ['Content-Type' => 'image/jpeg']]);
        Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

        $response = $this->postJson('/api/v1/uploads/prepare', [
            'protocol_version' => 1,
            'content_type' => 'image/jpeg',
            'byte_size' => 123_456,
        ]);

        $response->assertCreated()
            ->assertJsonPath('upload_url', 'https://r2.example.com/signed-put')
            ->assertJsonPath('headers.Content-Type', 'image/jpeg')
            ->assertJsonPath('data.protocol_version', 1)
            ->assertJsonPath('data.status', Upload::STATUS_UPLOADING);
        $this->assertDatabaseHas('uploads', [
            'user_id' => $user->id,
            'device_id' => $device->id,
            'status' => Upload::STATUS_UPLOADING,
            'size_bytes' => 123_456,
        ]);
    }

    public function test_prepare_rejects_a_disallowed_content_type(): void
    {
        $this->authenticateUploadUser(User::factory()->create());

        $response = $this->postJson('/api/v1/uploads/prepare', [
            'protocol_version' => 1,
            'content_type' => 'application/pdf',
            'byte_size' => 100,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('content_type');
    }

    public function test_commit_marks_the_upload_uploaded_when_the_object_exists_with_matching_size(): void
    {
        Storage::fake('r2');
        $device = $this->authenticateUploadUser($user = User::factory()->create());
        $bytes = $this->fakeImageBytes();
        $upload = Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'device_id' => $device->id,
            'object_key' => 'screenshots/1/abc.jpg',
            'nonce' => $this->nonce(),
            'protocol_version' => 1,
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($bytes),
            'status' => Upload::STATUS_UPLOADING,
            'expires_at' => now()->addMinutes(10),
        ]);
        Storage::disk('r2')->put('screenshots/1/abc.jpg', $bytes);

        $response = $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'protocol_version' => 1,
            'nonce' => $this->nonce(),
            'image_sha256' => hash('sha256', $bytes),
            'ocr_text' => 'hello world',
        ]);

        $response->assertOk()->assertJsonPath('data.status', Upload::STATUS_UPLOADED);
        $this->assertSame(Upload::STATUS_UPLOADED, $upload->fresh()->status);
        $this->assertSame('hello world', $upload->fresh()->ocr_text);
        $this->assertSame(10, $upload->fresh()->width);
        $this->assertSame(10, $upload->fresh()->height);
    }

    public function test_commit_rejects_when_the_object_never_landed_in_r2(): void
    {
        Storage::fake('r2');
        $device = $this->authenticateUploadUser($user = User::factory()->create());
        $upload = Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'device_id' => $device->id,
            'object_key' => 'screenshots/1/missing.jpg',
            'nonce' => $this->nonce(),
            'protocol_version' => 1,
            'status' => Upload::STATUS_UPLOADING,
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'protocol_version' => 1,
            'nonce' => $this->nonce(),
            'image_sha256' => str_repeat('b', 64),
        ]);

        $response->assertUnprocessable();
        $this->assertSame(Upload::STATUS_REJECTED, $upload->fresh()->status);
    }

    public function test_commit_rejects_a_size_mismatch_against_what_was_declared_at_prepare_time(): void
    {
        Storage::fake('r2');
        $device = $this->authenticateUploadUser($user = User::factory()->create());
        $bytes = $this->fakeImageBytes();
        $upload = Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'device_id' => $device->id,
            'object_key' => 'screenshots/1/swapped.jpg',
            'nonce' => $this->nonce(),
            'protocol_version' => 1,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 999_999,
            'status' => Upload::STATUS_UPLOADING,
            'expires_at' => now()->addMinutes(10),
        ]);
        Storage::disk('r2')->put('screenshots/1/swapped.jpg', $bytes);

        $response = $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'protocol_version' => 1,
            'nonce' => $this->nonce(),
            'image_sha256' => hash('sha256', $bytes),
        ]);

        $response->assertUnprocessable();
        $this->assertSame(Upload::STATUS_REJECTED, $upload->fresh()->status);
    }

    public function test_commit_is_not_allowed_for_someone_elses_upload(): void
    {
        $this->authenticateUploadUser(User::factory()->create());
        $ownerDevice = Device::factory()->for($owner = User::factory()->create())->create();
        $upload = Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'device_id' => $ownerDevice->id,
            'object_key' => 'screenshots/2/other.jpg',
            'nonce' => $this->nonce(),
            'protocol_version' => 1,
            'status' => Upload::STATUS_UPLOADING,
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'protocol_version' => 1,
            'nonce' => $this->nonce(),
            'image_sha256' => str_repeat('d', 64),
        ]);

        $response->assertNotFound();
    }

    public function test_commit_rejects_a_second_attempt_on_an_already_committed_upload(): void
    {
        Storage::fake('r2');
        $device = $this->authenticateUploadUser($user = User::factory()->create());
        $upload = Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'device_id' => $device->id,
            'object_key' => 'screenshots/1/twice.jpg',
            'nonce' => $this->nonce(),
            'protocol_version' => 1,
            'size_bytes' => 4,
            'status' => Upload::STATUS_UPLOADED,
            'expires_at' => now()->addMinutes(10),
        ]);
        Storage::disk('r2')->put('screenshots/1/twice.jpg', 'fake');

        $response = $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'protocol_version' => 1,
            'nonce' => $this->nonce(),
            'image_sha256' => str_repeat('e', 64),
        ]);

        $response->assertStatus(409);
    }

    public function test_commit_rejects_a_hash_mismatch(): void
    {
        Storage::fake('r2');
        $device = $this->authenticateUploadUser($user = User::factory()->create());
        $bytes = $this->fakeImageBytes();
        $upload = $this->uploading($user, $device, $bytes, 'image/jpeg');

        $this->postJson("/api/v1/uploads/{$upload->upload_id}/commit", [
            'protocol_version' => 1,
            'nonce' => $upload->nonce,
            'image_sha256' => str_repeat('a', 64),
        ])->assertUnprocessable();

        $this->assertSame(Upload::STATUS_REJECTED, $upload->fresh()->status);
    }

    public function test_commit_rejects_spoofed_mime_and_malformed_images(): void
    {
        Storage::fake('r2');
        $device = $this->authenticateUploadUser($user = User::factory()->create());

        $jpeg = $this->fakeImageBytes();
        $spoofed = $this->uploading($user, $device, $jpeg, 'image/png');
        $this->postJson("/api/v1/uploads/{$spoofed->upload_id}/commit", [
            'protocol_version' => 1,
            'nonce' => $spoofed->nonce,
            'image_sha256' => hash('sha256', $jpeg),
        ])->assertUnprocessable();

        $malformedBytes = 'not-an-image';
        $malformed = $this->uploading($user, $device, $malformedBytes, 'image/jpeg', 'malformed.jpg');
        $this->postJson("/api/v1/uploads/{$malformed->upload_id}/commit", [
            'protocol_version' => 1,
            'nonce' => $malformed->nonce,
            'image_sha256' => hash('sha256', $malformedBytes),
        ])->assertUnprocessable();
    }

    public function test_commit_rejects_expired_or_wrong_nonce_bindings(): void
    {
        Storage::fake('r2');
        $device = $this->authenticateUploadUser($user = User::factory()->create());
        $bytes = $this->fakeImageBytes();

        $expired = $this->uploading($user, $device, $bytes, 'image/jpeg', 'expired.jpg', now()->subSecond());
        $this->postJson("/api/v1/uploads/{$expired->upload_id}/commit", [
            'protocol_version' => 1,
            'nonce' => $expired->nonce,
            'image_sha256' => hash('sha256', $bytes),
        ])->assertStatus(410);

        $wrongNonce = $this->uploading($user, $device, $bytes, 'image/jpeg', 'wrong-nonce.jpg');
        $this->postJson("/api/v1/uploads/{$wrongNonce->upload_id}/commit", [
            'protocol_version' => 1,
            'nonce' => str_repeat('x', 43),
            'image_sha256' => hash('sha256', $bytes),
        ])->assertUnprocessable();
    }

    private function authenticateUploadUser(User $user): Device
    {
        $device = Device::factory()->create();
        $issued = $this->startUserSession($user, $device);
        $this->withToken($issued->token);

        return $device;
    }

    private function fakeImageBytes(): string
    {
        $file = UploadedFile::fake()->image('safe.jpg', 10, 10);

        return (string) file_get_contents($file->getRealPath());
    }

    private function uploading(
        User $user,
        Device $device,
        string $bytes,
        string $mime,
        string $filename = 'object.jpg',
        ?\DateTimeInterface $expiresAt = null,
    ): Upload {
        $path = "screenshots/{$user->id}/{$filename}";
        Storage::disk('r2')->put($path, $bytes);

        return Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'device_id' => $device->id,
            'object_key' => $path,
            'nonce' => substr(hash('sha256', $filename), 0, 43),
            'protocol_version' => 1,
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
            'status' => Upload::STATUS_UPLOADING,
            'expires_at' => $expiresAt ?? now()->addMinutes(10),
        ]);
    }

    private function nonce(): string
    {
        return str_repeat('n', 43);
    }
}
