<?php

use App\Actions\Media\CleanOrphanedMedia;
use App\Actions\Media\CreatePrivateSave;
use App\Contracts\MediaFileStore;
use App\Enums\MediaCleanupStatus;
use App\Models\MediaCleanupTask;
use App\Models\PrivateSave;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\PointRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['social.private_media_disk' => 'local', 'social.media_disk' => 'public']);
    Storage::fake('local');
    Storage::fake('public');
});

it('reserves cleanup before writing and retains it after an analytics rollback', function (): void {
    $images = Mockery::mock(ImageProcessingService::class);
    $images->shouldReceive('storeOriginal')->once()->andReturnUsing(function ($file, string $directory, $maxDimension = null, $diskName = null): array {
        expect(MediaCleanupTask::query()->where('directory', $directory)->value('source_disk'))->toBe('local');
        $path = $directory.'/image.png';
        Storage::disk('local')->put($path, 'image');

        return ['path' => $path, 'width' => 20, 'height' => 20, 'mime' => 'image/png', 'size' => 5];
    });
    $action = new CreatePrivateSave($images, app(PointRewardService::class));
    expect(fn () => $action(User::factory()->create(), UploadedFile::fake()->image('shot.png'), afterPersist: function (): void {
        throw new RuntimeException('analytics failed');
    }))->toThrow(RuntimeException::class, 'analytics failed');

    expect(PrivateSave::count())->toBe(0);
    $task = MediaCleanupTask::firstOrFail();
    $task->update(['available_at' => now()->subMinute()]);
    $summary = app(CleanOrphanedMedia::class)();
    expect($summary->purged)->toBe(1);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    expect(MediaCleanupTask::count())->toBe(0);
});

it('releases cleanup with a successful save and preserves its original', function (): void {
    $save = app(CreatePrivateSave::class)(User::factory()->create(), UploadedFile::fake()->image('shot.png'));
    expect(MediaCleanupTask::count())->toBe(0);
    Storage::disk('local')->assertExists($save->path);

    // Recover an orphaned reservation left by an interrupted response after commit.
    MediaCleanupTask::create([
        'directory' => dirname($save->path), 'source_disk' => 'local', 'available_at' => now(),
    ]);
    expect(app(CleanOrphanedMedia::class)()->alreadyGone)->toBe(1);
    Storage::disk('local')->assertExists($save->path);
});

it('leaves a retryable task when cleanup storage fails', function (): void {
    $task = MediaCleanupTask::create([
        'directory' => 'private-saves/orphan', 'source_disk' => 'local', 'available_at' => now(),
    ]);
    $files = Mockery::mock(MediaFileStore::class);
    $files->shouldReceive('deleteDirectory')->once()->with('private-saves/orphan', 'local')->andThrow(new RuntimeException('storage unavailable'));
    expect((new CleanOrphanedMedia($files))()->failed)->toBe(1);
    expect($task->fresh()->status)->toBe(MediaCleanupStatus::Failed);
    expect($task->fresh()->attempts)->toBe(1);
    $task->update(['available_at' => now()->subMinute()]);
    $retryFiles = Mockery::mock(MediaFileStore::class);
    $retryFiles->shouldReceive('deleteDirectory')->once()->with('private-saves/orphan', 'local');
    expect((new CleanOrphanedMedia($retryFiles))()->purged)->toBe(1);
    expect(MediaCleanupTask::count())->toBe(0);
});
