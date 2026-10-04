<?php

namespace Tests\Feature;

use App\Actions\Auth\CompleteSocialLogin;
use App\Actions\Devices\EnrollDevice;
use App\Actions\Media\PublishMediaAnalysisOnce;
use App\Actions\Posts\CreatePost;
use App\Data\Auth\DeviceSessionContext;
use App\Data\Devices\EnrollDeviceData;
use App\Data\Posts\CreatePostData;
use App\Exceptions\DeviceProofOfPossessionRequired;
use App\Jobs\ComputePostMediaPerceptualHash;
use App\Jobs\ExtractPostMediaText;
use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\Device;
use App\Models\MediaAnalysis;
use App\Models\MediaAnalysisItem;
use App\Models\Post;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Services\Screenshots\CaptureAnalytics;
use App\Services\SocialAuth\SocialUserPayload;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class PostgresSocialConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * `screenshot_categories` is reference data INSERTed by the migration that creates it
     * (2026_07_20_000010_add_screenshot_metadata), not by a seeder — so truncating it
     * destroys it for the rest of the process. Later classes use RefreshDatabase, which
     * finds RefreshDatabaseState::$migrated already true and never re-runs migrations, so
     * the rows never come back and every `ScreenshotCategory::firstOrFail()` downstream
     * blows up (RecommendationCandidateGenerationTest, RecommendationFeedbackAdministrationTest).
     *
     * The trait merges this with the migrations table, so both are preserved.
     *
     * @var list<string>
     */
    protected array $exceptTables = ['screenshot_categories'];

    /**
     * Both tests below fork real child processes that write on their own connections, so
     * their rows are committed rather than held in a rollback-able transaction — which is
     * exactly why this class uses DatabaseTruncation instead of RefreshDatabase.
     *
     * DatabaseTruncation only truncates *before* each test, never after the last one, and
     * on its very first run it returns early after `migrate:fresh` without truncating at
     * all. Every other class in the CI PostgreSQL run uses RefreshDatabase, which finds
     * RefreshDatabaseState::$migrated already true, skips its own `migrate:fresh`, and
     * merely opens a transaction — so whatever this class leaves committed survives the
     * entire run and breaks their absolute row counts (assertDatabaseCount). Clean up on
     * the way out so this class cannot influence whatever runs after it.
     */
    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_concurrent_first_social_login_creates_one_user_and_one_identity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency coverage runs in CI.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('The PostgreSQL CI runner must provide pcntl for concurrency coverage.');
        }

        $barrier = tempnam(sys_get_temp_dir(), 'social-login-barrier-');
        $this->assertNotFalse($barrier);
        unlink($barrier);
        $children = [];

        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);

            if ($pid === 0) {
                while (! file_exists($barrier)) {
                    usleep(1_000);
                }

                DB::disconnect();

                try {
                    $payload = new SocialUserPayload('google', 'concurrent-provider-id', 'concurrent@example.com', true, 'Concurrent User', null);
                    $deviceUuid = sprintf('11111111-1111-4111-8111-%012d', $index + 1);
                    $device = Device::query()->firstOrCreate(['device_uuid' => $deviceUuid], ['os_name' => 'Android']);
                    app(CompleteSocialLogin::class)(
                        $device,
                        $payload,
                        new DeviceSessionContext('ci-worker', '127.0.0.1', 'phpunit'),
                    );
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }

            $children[] = $pid;
        }

        touch($barrier);

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        unlink($barrier);
        DB::disconnect();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_concurrent_device_enrollment_creates_one_installation_and_requires_proof_for_the_loser(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency coverage runs in CI.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('The PostgreSQL CI runner must provide pcntl for concurrency coverage.');
        }

        $barrier = tempnam(sys_get_temp_dir(), 'device-enrollment-barrier-');
        $this->assertNotFalse($barrier);
        unlink($barrier);
        $results = [];
        $children = [];

        for ($index = 0; $index < 2; $index++) {
            $resultPath = sys_get_temp_dir()."/device-enrollment-result-{$index}-".uniqid('', true);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);

            if ($pid === 0) {
                while (! file_exists($barrier)) {
                    usleep(1_000);
                }

                DB::disconnect();

                try {
                    app(EnrollDevice::class)(new EnrollDeviceData(
                        '22222222-2222-4222-8222-222222222222',
                        'Google',
                        'google',
                        'Pixel',
                        'Android',
                        '14',
                        34,
                        '3.0',
                        30,
                    ), null);
                    file_put_contents($resultPath, 'created');
                    exit(0);
                } catch (DeviceProofOfPossessionRequired) {
                    file_put_contents($resultPath, 'proof_required');
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }

            $children[] = $pid;
            $results[] = $resultPath;
        }

        touch($barrier);

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        unlink($barrier);
        DB::disconnect();
        $outcomes = array_map(static fn (string $path): string => (string) file_get_contents($path), $results);
        array_map('unlink', $results);

        $this->assertEqualsCanonicalizing(['created', 'proof_required'], $outcomes);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_concurrent_publication_retries_return_the_same_post(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency coverage runs in CI.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('The PostgreSQL CI runner must provide pcntl for concurrency coverage.');
        }

        Queue::fake();
        $user = User::factory()->create();
        $token = (string) Str::uuid();
        $analysis = MediaAnalysis::create([
            'token' => $token,
            'user_id' => $user->id,
            'directory' => 'analyses/'.$token,
            'status' => MediaAnalysis::STATUS_READY,
            'expires_at' => now()->addMinutes(30),
        ]);
        MediaAnalysisItem::create([
            'media_analysis_id' => $analysis->id,
            'position' => 0,
            'original_path' => 'analyses/'.$token.'/original.jpg',
            'width' => 100,
            'height' => 100,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'ocr_status' => 'ready',
            'safety_status' => 'clear',
            'analysis_version' => 'concurrency-test',
        ]);

        $barrier = tempnam(sys_get_temp_dir(), 'publish-retry-barrier-');
        $this->assertNotFalse($barrier);
        unlink($barrier);
        $results = [];
        $children = [];

        for ($index = 0; $index < 2; $index++) {
            $resultPath = sys_get_temp_dir()."/publish-retry-result-{$index}-".uniqid('', true);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);

            if ($pid === 0) {
                while (! file_exists($barrier)) {
                    usleep(1_000);
                }

                DB::disconnect();

                try {
                    $post = app(PublishMediaAnalysisOnce::class)($user, $token, ['caption' => 'Concurrent publish']);
                    file_put_contents($resultPath, (string) $post->id);
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($resultPath, 'error:'.$exception::class.':'.$exception->getMessage());
                    exit(1);
                }
            }

            $children[] = $pid;
            $results[] = $resultPath;
        }

        touch($barrier);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        unlink($barrier);
        DB::disconnect();
        $postIds = array_map(static fn (string $path): string => (string) file_get_contents($path), $results);
        array_map('unlink', $results);

        $this->assertCount(2, $postIds);
        $this->assertSame($postIds[0], $postIds[1]);
        $this->assertSame(1, Post::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseCount('media_publication_receipts', 1);
    }

    public function test_concurrent_capture_claims_cannot_assign_one_capture_to_two_devices(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency coverage runs in CI.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('The PostgreSQL CI runner must provide pcntl for concurrency coverage.');
        }

        $devices = [Device::factory()->create(), Device::factory()->create()];
        $captureId = (string) Str::uuid();
        $barrier = tempnam(sys_get_temp_dir(), 'capture-claim-barrier-');
        $this->assertNotFalse($barrier);
        unlink($barrier);
        $children = [];
        $results = [];

        foreach ($devices as $index => $device) {
            $resultPath = sys_get_temp_dir()."/capture-claim-result-{$index}-".uniqid('', true);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);

            if ($pid === 0) {
                while (! file_exists($barrier)) {
                    usleep(1_000);
                }

                DB::disconnect();

                try {
                    app(CaptureAnalytics::class)->claim($captureId, $device->id);
                    file_put_contents($resultPath, 'claimed');
                    exit(0);
                } catch (ValidationException) {
                    file_put_contents($resultPath, 'rejected');
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($resultPath, 'error:'.$exception::class.':'.$exception->getMessage());
                    exit(1);
                }
            }

            $children[] = $pid;
            $results[] = $resultPath;
        }

        touch($barrier);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        unlink($barrier);
        DB::disconnect();
        $outcomes = array_map(static fn (string $path): string => (string) file_get_contents($path), $results);
        array_map('unlink', $results);

        $this->assertEqualsCanonicalizing(['claimed', 'rejected'], $outcomes);
        $this->assertSame(1, DB::table('screenshot_captures')->where('id', $captureId)->count());
        $this->assertContains((int) DB::table('screenshot_captures')->where('id', $captureId)->value('device_id'), array_map(
            static fn (Device $device): int => (int) $device->id,
            $devices,
        ));
    }

    public function test_a_concurrent_library_snapshot_upload_cannot_replace_a_newer_observation(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency coverage runs in CI.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('The PostgreSQL CI runner must provide pcntl for concurrency coverage.');
        }

        $device = Device::factory()->create();
        $olderAt = now()->subMinutes(2);
        $newerAt = now()->subMinute();
        $locked = tempnam(sys_get_temp_dir(), 'library-snapshot-locked-');
        $release = tempnam(sys_get_temp_dir(), 'library-snapshot-release-');
        $newerStarted = tempnam(sys_get_temp_dir(), 'library-snapshot-newer-');
        $this->assertNotFalse($locked);
        $this->assertNotFalse($release);
        $this->assertNotFalse($newerStarted);
        unlink($locked);
        unlink($release);
        unlink($newerStarted);

        $olderPid = pcntl_fork();
        $this->assertNotSame(-1, $olderPid);
        if ($olderPid === 0) {
            DB::disconnect();
            try {
                DB::beginTransaction();
                Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
                touch($locked);
                while (! file_exists($release)) {
                    usleep(1_000);
                }
                DB::table('screenshot_library_snapshots')->updateOrInsert(['device_id' => $device->id], [
                    'user_id' => null,
                    'screenshot_count' => 11,
                    'coverage' => 'full',
                    'observed_at' => $olderAt,
                ]);
                DB::commit();
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($locked.'.error', $exception::class.':'.$exception->getMessage());
                DB::rollBack();
                exit(1);
            }
        }

        while (! file_exists($locked)) {
            usleep(1_000);
        }

        $newerPid = pcntl_fork();
        $this->assertNotSame(-1, $newerPid);
        if ($newerPid === 0) {
            while (! file_exists($locked)) {
                usleep(1_000);
            }
            DB::disconnect();
            try {
                $backendPid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                file_put_contents($newerStarted, (string) $backendPid);
                $event = new TelemetryEvent;
                $event->kind = 'event';
                $event->name = 'screenshot_library_v1';
                $event->device_id = $device->id;
                $event->user_id = null;
                $event->occurred_at = $newerAt;
                $event->extras = ['coverage' => 'full', 'count' => 23];
                app(CaptureAnalytics::class)->ingest($event);
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($newerStarted.'.error', $exception::class.':'.$exception->getMessage());
                exit(1);
            }
        }

        $lockWaitDeadline = microtime(true) + 5;
        $isWaitingOnDeviceLock = false;
        while (microtime(true) < $lockWaitDeadline) {
            if (file_exists($newerStarted)) {
                $backendPid = (int) file_get_contents($newerStarted);
                $activity = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendPid]);
                if (($activity->wait_event_type ?? null) === 'Lock') {
                    $isWaitingOnDeviceLock = true;
                    break;
                }
            }
            usleep(1_000);
        }
        touch($release);
        $this->assertTrue($isWaitingOnDeviceLock, 'Newer snapshot upload should wait for the older transaction device lock.');

        pcntl_waitpid($olderPid, $olderStatus);
        pcntl_waitpid($newerPid, $newerStatus);
        DB::disconnect();
        $this->assertSame(0, pcntl_wexitstatus($olderStatus), file_exists($locked.'.error') ? (string) file_get_contents($locked.'.error') : '');
        $this->assertSame(0, pcntl_wexitstatus($newerStatus), file_exists($newerStarted.'.error') ? (string) file_get_contents($newerStarted.'.error') : '');
        array_map('unlink', [$locked, $release, $newerStarted]);
        @unlink($locked.'.error');
        @unlink($newerStarted.'.error');

        $snapshot = DB::table('screenshot_library_snapshots')->where('device_id', $device->id)->firstOrFail();
        $this->assertSame(23, (int) $snapshot->screenshot_count);
        $this->assertEquals($newerAt->timestamp, CarbonImmutable::parse($snapshot->observed_at)->timestamp);
    }

    public function test_post_processing_jobs_wait_for_the_outermost_commit_and_rollback_discards_them(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL transaction-ordering coverage runs in CI.');
        }

        Storage::fake('public');
        $queueDatabase = config('database.connections.pgsql');
        config([
            'database.connections.queue_observer' => $queueDatabase,
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'queue_observer',
            'queue.connections.database.after_commit' => false,
        ]);
        DB::purge('queue_observer');
        DB::connection('queue_observer')->table('jobs')->delete();

        $user = User::factory()->create();
        $mentioned = User::factory()->create(['username' => 'after_commit_mention']);
        $createPost = app(CreatePost::class);

        DB::beginTransaction();
        $rolledBackPost = $createPost($user, new CreatePostData('@after_commit_mention', [UploadedFile::fake()->image('rolled-back.jpg')]));
        $this->assertSame(0, DB::connection('queue_observer')->table('jobs')->count());
        DB::rollBack();

        $this->assertDatabaseMissing('posts', ['id' => $rolledBackPost->id]);
        $this->assertSame(0, DB::connection('queue_observer')->table('jobs')->count());

        DB::beginTransaction();
        $committedPost = $createPost($user, new CreatePostData('@after_commit_mention', [UploadedFile::fake()->image('committed.jpg')]));
        $mediaId = (int) $committedPost->media->firstOrFail()->id;
        $this->assertSame(0, DB::connection('queue_observer')->table('jobs')->count());
        DB::commit();

        $this->assertSame(5, DB::connection('queue_observer')->table('jobs')->count());
        $this->assertTrue(DB::connection('queue_observer')->table('post_media')->where('id', $mediaId)->exists());
        $this->assertTrue(DB::connection('queue_observer')->table('mentions')
            ->where('mentionable_id', $committedPost->id)
            ->where('mentioned_user_id', $mentioned->id)
            ->exists());
        $queuedClasses = DB::connection('queue_observer')->table('jobs')->pluck('payload')
            ->map(fn (string $payload): string => unserialize(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['command'])::class)
            ->all();
        $this->assertEqualsCanonicalizing([
            GeneratePostMediaThumbnail::class,
            ExtractPostMediaText::class,
            ComputePostMediaPerceptualHash::class,
            SendQueuedNotifications::class,
            SendQueuedNotifications::class,
        ], $queuedClasses);

        DB::connection('queue_observer')->table('jobs')->delete();
    }
}
