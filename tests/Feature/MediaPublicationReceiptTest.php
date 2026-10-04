<?php

use App\Actions\Media\CreateMediaAnalysis;
use App\Actions\Media\PublishMediaAnalysisOnce;
use App\Contracts\ScreenshotTextExtractor;
use App\Data\Screenshots\TextExtractionResult;
use App\Jobs\ComputePostMediaPerceptualHash;
use App\Models\MediaAnalysis;
use App\Models\Post;
use App\Models\User;
use App\Notifications\MentionedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $extractor = Mockery::mock(ScreenshotTextExtractor::class);
    $extractor->allows('version')->andReturn('receipt-test');
    $extractor->allows('extract')->andReturn(new TextExtractionResult('A useful idea', 'eng'));
    app()->instance(ScreenshotTextExtractor::class, $extractor);
    $this->owner = User::factory()->create();
    $this->analysis = app(CreateMediaAnalysis::class)($this->owner, [UploadedFile::fake()->image('shot.png')]);
    expect($this->analysis->status)->toBe(MediaAnalysis::STATUS_READY);
    Queue::fake();
    Sanctum::actingAs($this->owner);
});

it('replays a committed publication after the client loses its success response', function (): void {
    $url = '/api/v1/media/analyses/'.$this->analysis->token.'/publish';
    $first = $this->postJson($url, ['caption' => 'Caption'])->assertCreated();
    expect(MediaAnalysis::find($this->analysis->id))->toBeNull();
    $second = $this->postJson($url, ['caption' => 'Caption'])->assertCreated();
    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(Post::count())->toBe(1);
    expect(DB::table('media_publication_receipts')->count())->toBe(1);
});

it('rejects another user and changed publish input without creating another post', function (): void {
    $url = '/api/v1/media/analyses/'.$this->analysis->token.'/publish';
    $this->postJson($url, ['caption' => 'original'])->assertCreated();
    $this->postJson($url, ['caption' => 'changed'])->assertConflict();
    Sanctum::actingAs(User::factory()->create());
    $this->postJson($url, ['caption' => 'original'])->assertNotFound();
    expect(Post::count())->toBe(1);
});

it('rolls the receipt back with the enclosing publication transaction', function (): void {
    expect(function (): void {
        DB::transaction(function (): void {
            app(PublishMediaAnalysisOnce::class)($this->owner, $this->analysis->token, ['caption' => 'retryable']);
            throw new RuntimeException('commit aborted');
        });
    })->toThrow(RuntimeException::class, 'commit aborted');
    expect(Post::count())->toBe(0);
    expect(DB::table('media_publication_receipts')->count())->toBe(0);
    expect(MediaAnalysis::find($this->analysis->id))->not->toBeNull();
    $this->postJson('/api/v1/media/analyses/'.$this->analysis->token.'/publish', ['caption' => 'retryable'])->assertCreated();
    expect(Post::count())->toBe(1);
});

it('does not replay a deleted post or expose malformed receipt keys', function (): void {
    $url = '/api/v1/media/analyses/'.$this->analysis->token.'/publish';
    $this->postJson($url)->assertCreated();
    Post::firstOrFail()->delete();
    $this->postJson($url)->assertNotFound();
    $this->postJson('/api/v1/media/analyses/not-a-uuid/publish')->assertNotFound();
    expect(Post::withTrashed()->count())->toBe(1);
});

it('defers queued mention notifications and media hashing until the outer transaction commits', function (): void {
    $mentioned = User::factory()->create(['username' => 'mentioned_user']);
    Notification::fake();
    $post = $this->postJson('/api/v1/media/analyses/'.$this->analysis->token.'/publish', [
        'caption' => 'Useful idea @mentioned_user',
    ])->assertCreated();

    Queue::assertPushed(ComputePostMediaPerceptualHash::class, fn ($job): bool => $job->afterCommit === true);
    Notification::assertSentTo(
        $mentioned,
        MentionedNotification::class,
        fn (MentionedNotification $notification): bool => $notification->afterCommit === true,
    );
    expect($post->json('data.id'))->toBeGreaterThan(0);
});
