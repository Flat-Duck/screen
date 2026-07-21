<?php

namespace Tests\Feature;

use App\Actions\Auth\CompleteSocialLogin;
use App\Actions\Media\CleanOrphanedMedia;
use App\Actions\Security\DispatchPendingSecurityOutbox;
use App\Actions\Telemetry\IngestTelemetryBatch;
use App\Actions\Telemetry\PersistTelemetryEvent;
use App\Contracts\MediaFileStore;
use App\Data\Auth\DeviceSessionContext;
use App\Data\Telemetry\TelemetryBatchData;
use App\Data\Telemetry\TelemetryEventData;
use App\Enums\SecurityOutboxStatus;
use App\Enums\SecurityOutboxType;
use App\Enums\TelemetryKind;
use App\Jobs\DeliverSecurityOutboxMessage;
use App\Jobs\ImportSocialAvatar;
use App\Mail\EmailChangedNotificationMail;
use App\Models\Device;
use App\Models\MediaCleanupTask;
use App\Models\PostMedia;
use App\Models\SecurityOutboxMessage;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\SocialAuth\SocialUserPayload;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WorkflowDurabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_outbox_delivery_marks_the_message_sent(): void
    {
        Mail::fake();
        $message = SecurityOutboxMessage::create([
            'type' => SecurityOutboxType::EmailChangedNotification,
            'recipient' => 'old@example.com',
            'payload' => ['new_email' => 'new@example.com'],
            'status' => SecurityOutboxStatus::Pending,
            'available_at' => now(),
        ]);

        (new DeliverSecurityOutboxMessage($message->id))->handle();

        Mail::assertSent(EmailChangedNotificationMail::class, fn ($mail): bool => $mail->hasTo('old@example.com'));
        $this->assertSame(SecurityOutboxStatus::Sent, $message->fresh()->status);
    }

    public function test_stale_security_outbox_messages_are_recovered_and_dispatched(): void
    {
        Queue::fake();
        $message = SecurityOutboxMessage::create([
            'type' => SecurityOutboxType::EmailChangedNotification,
            'recipient' => 'old@example.com',
            'payload' => ['new_email' => 'new@example.com'],
            'status' => SecurityOutboxStatus::Processing,
            'processing_started_at' => now()->subHour(),
            'available_at' => now()->subHour(),
        ]);

        $this->assertSame(1, app(DispatchPendingSecurityOutbox::class)());
        Queue::assertPushed(DeliverSecurityOutboxMessage::class, fn ($job): bool => $job->messageId === $message->id);
    }

    public function test_orphan_cleanup_deletes_unreferenced_media_but_protects_referenced_media(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('posts/orphan/file.jpg', 'x');
        Storage::disk('public')->put('posts/live/file.jpg', 'x');
        MediaCleanupTask::create(['directory' => 'posts/orphan', 'available_at' => now()]);
        MediaCleanupTask::create(['directory' => 'posts/live', 'available_at' => now()]);
        PostMedia::factory()->create(['original_path' => 'posts/live/file.jpg']);

        $summary = app(CleanOrphanedMedia::class)();

        $this->assertSame(1, $summary->purged);
        $this->assertSame(1, $summary->alreadyGone);
        Storage::disk('public')->assertMissing('posts/orphan/file.jpg');
        Storage::disk('public')->assertExists('posts/live/file.jpg');
        $this->assertDatabaseCount('media_cleanup_tasks', 0);
    }

    public function test_social_account_commits_before_avatar_job_is_dispatched_after_commit(): void
    {
        Queue::fake();
        $payload = new SocialUserPayload('google', 'provider-1', 'new@example.com', true, 'New User', 'https://example.com/avatar.jpg');

        app(CompleteSocialLogin::class)(
            Device::factory()->create(),
            $payload,
            new DeviceSessionContext('mobile', '127.0.0.1', 'phpunit'),
        );

        $this->assertDatabaseHas('social_accounts', ['provider_user_id' => 'provider-1']);
        Queue::assertPushed(ImportSocialAvatar::class, fn ($job): bool => $job->queue === 'media');
    }

    public function test_social_avatar_import_updates_only_an_empty_avatar_and_clears_its_cleanup_reservation(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar_path' => null]);
        $images = Mockery::mock(ImageProcessingService::class);
        $images->shouldReceive('storeFromUrl')->once()->andReturnUsing(function (string $url, string $directory): array {
            Storage::disk('public')->put("{$directory}/avatar.webp", 'image');

            return ['path' => "{$directory}/avatar.webp", 'width' => 100, 'height' => 100, 'mime' => 'image/webp', 'size' => 5];
        });

        (new ImportSocialAvatar($user->id, 'https://example.com/avatar'))->handle($images, app(MediaFileStore::class));

        $this->assertNotNull($user->fresh()->avatar_path);
        $this->assertDatabaseCount('media_cleanup_tasks', 0);
    }

    public function test_telemetry_metadata_rolls_back_when_event_persistence_fails(): void
    {
        $device = Device::factory()->create(['app_version_name' => 'old', 'last_seen_at' => null]);
        $event = new TelemetryEventData('event-1', null, TelemetryKind::Event, 'opened', now()->toImmutable(), [], [], null, null, null, null, null, false);
        $batch = new TelemetryBatchData('new', 2, 'release', '14', [$event]);
        $persist = Mockery::mock(PersistTelemetryEvent::class);
        $persist->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('insert failed'));

        try {
            (new IngestTelemetryBatch($persist))($device, $batch);
            $this->fail('Expected ingestion failure.');
        } catch (RuntimeException) {
            $this->assertSame('old', $device->fresh()->app_version_name);
            $this->assertNull($device->fresh()->last_seen_at);
        }
    }

    public function test_maintenance_schedules_are_single_server_and_non_overlapping(): void
    {
        $commands = ['posts:prune-deleted', 'users:prune-deleted', 'media:clean-orphans', 'security-outbox:dispatch', 'telemetry:prune', 'sessions:expire', 'posts:refresh-trending', 'recommendations:refresh-pools', 'recommendations:prune-sessions'];
        $events = app(Schedule::class)->events();

        foreach ($commands as $command) {
            $event = collect($events)->first(fn ($event): bool => str_contains($event->command ?? '', $command));
            $this->assertNotNull($event, "Missing schedule for {$command}.");
            $this->assertTrue($event->onOneServer, "{$command} must run on one server.");
            $this->assertTrue($event->withoutOverlapping, "{$command} must not overlap.");
        }
    }
}
