<?php

namespace Tests\Feature\Api\V1;

use App\Models\PrivateSave;
use App\Models\PrivateSaveFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateSaveFolderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_folders_seeds_the_three_defaults_for_a_new_account(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $response = $this->getJson('/api/v1/private-save-folders');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'general')
            ->assertJsonPath('data.1.slug', 'business')
            ->assertJsonPath('data.2.slug', 'memes')
            ->assertJsonPath('data.0.name', 'General')
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.0.saves_count', 0);

        $this->assertSame(3, $user->privateSaveFolders()->count());
    }

    public function test_listing_folders_is_idempotent_and_does_not_duplicate_defaults(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->getJson('/api/v1/private-save-folders')->assertOk();
        $this->getJson('/api/v1/private-save-folders')->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame(3, $user->privateSaveFolders()->count());
    }

    public function test_folders_report_how_many_saves_they_hold(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->getJson('/api/v1/private-save-folders');
        $memes = $user->privateSaveFolders()->where('slug', 'memes')->firstOrFail();
        PrivateSave::factory()->for($user)->count(2)->create(['folder_id' => $memes->id]);

        $response = $this->getJson('/api/v1/private-save-folders');

        $response->assertOk()
            ->assertJsonPath('data.2.slug', 'memes')
            ->assertJsonPath('data.2.saves_count', 2)
            ->assertJsonPath('data.0.saves_count', 0);
    }

    public function test_folders_are_scoped_to_the_viewer(): void
    {
        $other = User::factory()->create();
        PrivateSaveFolder::factory()->for($other)->count(4)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/private-save-folders')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_uploading_without_a_folder_files_the_save_under_general(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($user = User::factory()->create());

        $response = $this->postJson('/api/v1/private-saves', [
            'image' => UploadedFile::fake()->image('screen.png', 400, 800),
        ]);

        $general = $user->privateSaveFolders()->where('slug', 'general')->firstOrFail();
        $response->assertCreated()
            ->assertJsonPath('data.folder_id', $general->id)
            ->assertJsonPath('data.folder.slug', 'general');
        $this->assertSame($general->id, PrivateSave::firstOrFail()->folder_id);
    }

    public function test_uploading_into_a_chosen_folder_files_it_there(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($user = User::factory()->create());
        $this->getJson('/api/v1/private-save-folders');
        $business = $user->privateSaveFolders()->where('slug', 'business')->firstOrFail();

        $response = $this->postJson('/api/v1/private-saves', [
            'image' => UploadedFile::fake()->image('receipt.png', 400, 800),
            'folder_id' => $business->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.folder_id', $business->id)
            ->assertJsonPath('data.folder.name', 'Business');
    }

    public function test_uploading_into_someone_elses_folder_is_rejected(): void
    {
        Storage::fake('local');
        $theirs = PrivateSaveFolder::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/private-saves', [
            'image' => UploadedFile::fake()->image('screen.png', 400, 800),
            'folder_id' => $theirs->id,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('folder_id');
        $this->assertSame(0, PrivateSave::query()->count());
    }

    public function test_index_can_be_filtered_to_a_single_folder(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->getJson('/api/v1/private-save-folders');
        $memes = $user->privateSaveFolders()->where('slug', 'memes')->firstOrFail();
        $general = $user->privateSaveFolders()->where('slug', 'general')->firstOrFail();
        PrivateSave::factory()->for($user)->count(2)->create(['folder_id' => $memes->id]);
        PrivateSave::factory()->for($user)->create(['folder_id' => $general->id]);

        $response = $this->getJson("/api/v1/private-saves?folder_id={$memes->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.folder.slug', 'memes');
        $this->getJson('/api/v1/private-saves')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_index_rejects_a_folder_belonging_to_someone_else(): void
    {
        $theirs = PrivateSaveFolder::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/private-saves?folder_id={$theirs->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('folder_id');
    }

    public function test_a_save_can_be_moved_to_another_folder(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->getJson('/api/v1/private-save-folders');
        $general = $user->privateSaveFolders()->where('slug', 'general')->firstOrFail();
        $memes = $user->privateSaveFolders()->where('slug', 'memes')->firstOrFail();
        $save = PrivateSave::factory()->for($user)->create(['folder_id' => $general->id]);

        $response = $this->patchJson("/api/v1/private-saves/{$save->id}", ['folder_id' => $memes->id]);

        $response->assertOk()
            ->assertJsonPath('data.folder_id', $memes->id)
            ->assertJsonPath('data.folder.slug', 'memes');
        $this->assertSame($memes->id, $save->refresh()->folder_id);
    }

    public function test_moving_someone_elses_save_is_not_allowed(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->getJson('/api/v1/private-save-folders');
        $mine = $user->privateSaveFolders()->where('slug', 'memes')->firstOrFail();
        $theirSave = PrivateSave::factory()->create();

        $this->patchJson("/api/v1/private-saves/{$theirSave->id}", ['folder_id' => $mine->id])
            ->assertNotFound();
        $this->assertNull($theirSave->refresh()->folder_id);
    }

    public function test_moving_into_someone_elses_folder_is_rejected(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $save = PrivateSave::factory()->for($user)->create();
        $theirFolder = PrivateSaveFolder::factory()->create();

        $this->patchJson("/api/v1/private-saves/{$save->id}", ['folder_id' => $theirFolder->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('folder_id');
    }

    public function test_a_save_with_no_folder_serializes_folder_as_null(): void
    {
        // Only reachable for a row that predates the backfill or whose folder was removed, but
        // the field is documented as nullable — so it must not blow up or emit a half-built object.
        Sanctum::actingAs($user = User::factory()->create());
        PrivateSave::factory()->for($user)->create(['folder_id' => null]);

        $this->getJson('/api/v1/private-saves')
            ->assertOk()
            ->assertJsonPath('data.0.folder_id', null)
            ->assertJsonPath('data.0.folder', null);
    }

    /**
     * The create migration also backfills, and a fresh test database has no rows for it to touch —
     * so roll the migration back over seeded data and run it forward again. Without this, a
     * mistake in the backfill would only ever show up on the production deploy.
     */
    public function test_the_migration_backfills_existing_accounts_and_files_their_saves(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $save = PrivateSave::factory()->for($user)->create();
        PrivateSaveFolder::query()->delete();

        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->artisan('migrate')->assertSuccessful();

        foreach ([$user, $other] as $account) {
            $this->assertSame(
                ['general', 'business', 'memes'],
                $account->privateSaveFolders()->pluck('slug')->all(),
                "account {$account->id} did not get the default folders",
            );
            $this->assertTrue($account->privateSaveFolders()->get()->every->is_default);
        }

        $general = $user->privateSaveFolders()->where('slug', 'general')->firstOrFail();
        $this->assertSame($general->id, $save->refresh()->folder_id);
    }

    public function test_deleting_a_user_takes_their_folders_with_them(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->getJson('/api/v1/private-save-folders');

        $user->forceDelete();

        $this->assertSame(0, PrivateSaveFolder::query()->where('user_id', $user->id)->count());
    }
}
