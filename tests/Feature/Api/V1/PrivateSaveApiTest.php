<?php

namespace Tests\Feature\Api\V1;

use App\Models\PrivateSave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateSaveApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_storing_a_private_save_uploads_and_returns_it(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($user = User::factory()->create());

        $response = $this->postJson('/api/v1/private-saves', [
            'image' => UploadedFile::fake()->image('screen.png', 400, 800),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.width', 400)
            ->assertJsonPath('data.height', 800);
        $save = PrivateSave::firstOrFail();
        $this->assertSame($user->id, $save->user_id);
        $this->assertSame('local', $save->source_disk);
        Storage::disk('local')->assertExists($save->path);
    }

    public function test_index_only_returns_the_viewers_own_saves(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        PrivateSave::factory()->for($user)->count(2)->create();
        PrivateSave::factory()->create();

        $response = $this->getJson('/api/v1/private-saves');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_destroy_deletes_the_file_and_the_row(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($user = User::factory()->create());
        Storage::disk('local')->put('private-saves/1/shot.jpg', 'fake');
        $save = PrivateSave::factory()->for($user)->create([
            'path' => 'private-saves/1/shot.jpg',
            'source_disk' => 'local',
        ]);

        $response = $this->deleteJson("/api/v1/private-saves/{$save->id}");

        $response->assertNoContent();
        $this->assertModelMissing($save);
        Storage::disk('local')->assertMissing('private-saves/1/shot.jpg');
    }

    public function test_destroy_is_not_allowed_for_someone_elses_save(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $save = PrivateSave::factory()->create();

        $response = $this->deleteJson("/api/v1/private-saves/{$save->id}");

        $response->assertNotFound();
        $this->assertModelExists($save);
    }

    public function test_storing_requires_an_image(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/private-saves', []);

        $response->assertUnprocessable()->assertJsonValidationErrors('image');
    }
}
