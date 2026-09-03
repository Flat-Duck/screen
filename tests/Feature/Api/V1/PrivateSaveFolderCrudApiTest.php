<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Media\CreatePrivateSaveFolder;
use App\Models\PrivateSave;
use App\Models\PrivateSaveFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateSaveFolderCrudApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->getJson('/api/v1/private-save-folders')->assertOk(); // seeds the three defaults

        return $user;
    }

    public function test_creating_a_folder_appends_it_after_the_defaults(): void
    {
        $user = $this->actingUser();

        $this->postJson('/api/v1/private-save-folders', ['name' => 'Receipts'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Receipts')
            ->assertJsonPath('data.slug', 'receipts')
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.position', 3)
            ->assertJsonPath('data.saves_count', 0);

        $this->assertSame(
            ['general', 'business', 'memes', 'receipts'],
            $user->privateSaveFolders()->pluck('slug')->all(),
        );
    }

    /** The defaults must keep positions 0-2 even for an account that creates a folder first. */
    public function test_creating_a_folder_first_still_seeds_the_defaults_below_it(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->postJson('/api/v1/private-save-folders', ['name' => 'Receipts'])->assertCreated();

        $this->assertSame(
            ['general', 'business', 'memes', 'receipts'],
            $user->privateSaveFolders()->pluck('slug')->all(),
        );
    }

    /**
     * Two folders may legitimately be called "Work"; the slug is an internal key, so it gets a
     * suffix rather than the request being rejected over a name the user can see nothing wrong with.
     */
    public function test_a_duplicate_name_gets_a_suffixed_slug_not_an_error(): void
    {
        $this->actingUser();

        $this->postJson('/api/v1/private-save-folders', ['name' => 'Work'])
            ->assertCreated()->assertJsonPath('data.slug', 'work');
        $this->postJson('/api/v1/private-save-folders', ['name' => 'Work'])
            ->assertCreated()->assertJsonPath('data.slug', 'work-2');
        $this->postJson('/api/v1/private-save-folders', ['name' => 'Work'])
            ->assertCreated()->assertJsonPath('data.slug', 'work-3');
    }

    /** A name that slugs to nothing (emoji, non-Latin script) must not produce a bare "-2". */
    public function test_a_name_with_no_sluggable_characters_still_gets_a_usable_slug(): void
    {
        $this->actingUser();

        $this->postJson('/api/v1/private-save-folders', ['name' => '🎮'])
            ->assertCreated()->assertJsonPath('data.slug', 'folder');
        $this->postJson('/api/v1/private-save-folders', ['name' => '📸'])
            ->assertCreated()->assertJsonPath('data.slug', 'folder-2');
    }

    public function test_creating_requires_a_name_and_caps_the_length(): void
    {
        $this->actingUser();

        $this->postJson('/api/v1/private-save-folders', [])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/private-save-folders', ['name' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/private-save-folders', ['name' => str_repeat('a', 61)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_folders_are_capped_per_account(): void
    {
        $user = $this->actingUser();
        $existing = $user->privateSaveFolders()->count();
        PrivateSaveFolder::factory()
            ->for($user)
            ->count(CreatePrivateSaveFolder::MAX_FOLDERS_PER_USER - $existing)
            ->create();

        $this->postJson('/api/v1/private-save-folders', ['name' => 'One too many'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_renaming_keeps_the_slug_stable(): void
    {
        $user = $this->actingUser();
        $general = $user->privateSaveFolders()->where('slug', 'general')->firstOrFail();

        $this->patchJson("/api/v1/private-save-folders/{$general->id}", ['name' => 'Everything else'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Everything else')
            // A client matching the seeded folders on `slug` must not break because of a rename.
            ->assertJsonPath('data.slug', 'general')
            ->assertJsonPath('data.is_default', true);
    }

    public function test_deleting_a_custom_folder_refiles_its_saves_under_general(): void
    {
        $user = $this->actingUser();
        $general = $user->privateSaveFolders()->where('slug', 'general')->firstOrFail();
        $folderId = $this->postJson('/api/v1/private-save-folders', ['name' => 'Receipts'])
            ->assertCreated()->json('data.id');
        $save = PrivateSave::factory()->for($user)->create(['folder_id' => $folderId]);

        $this->deleteJson("/api/v1/private-save-folders/{$folderId}")->assertNoContent();

        // The screenshots are the point — deleting a folder must never take images with it, and
        // the FK's nullOnDelete would otherwise leave them unfiled and invisible to every filter.
        $this->assertSame($general->id, $save->refresh()->folder_id);
        $this->assertDatabaseMissing('private_save_folders', ['id' => $folderId]);
    }

    public function test_the_seeded_folders_cannot_be_deleted(): void
    {
        $user = $this->actingUser();
        $memes = $user->privateSaveFolders()->where('slug', 'memes')->firstOrFail();

        $this->deleteJson("/api/v1/private-save-folders/{$memes->id}")->assertUnprocessable();

        $this->assertDatabaseHas('private_save_folders', ['id' => $memes->id]);
    }

    public function test_someone_elses_folder_is_not_reachable(): void
    {
        $theirs = PrivateSaveFolder::factory()->create();
        $this->actingUser();

        $this->patchJson("/api/v1/private-save-folders/{$theirs->id}", ['name' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/api/v1/private-save-folders/{$theirs->id}")->assertNotFound();
        $this->assertSame('Mine now', 'Mine now');
        $this->assertDatabaseHas('private_save_folders', ['id' => $theirs->id, 'name' => $theirs->name]);
    }

    public function test_a_new_folder_can_immediately_receive_a_save(): void
    {
        $this->actingUser();
        $folderId = $this->postJson('/api/v1/private-save-folders', ['name' => 'Receipts'])
            ->assertCreated()->json('data.id');

        Storage::fake('local');
        $this->postJson('/api/v1/private-saves', [
            'image' => UploadedFile::fake()->image('r.png', 100, 100),
            'folder_id' => $folderId,
        ])->assertCreated()->assertJsonPath('data.folder.name', 'Receipts');
    }
}
