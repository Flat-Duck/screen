<?php

namespace Tests\Feature;

use App\Actions\Auth\CompleteSocialLogin;
use App\Actions\Devices\EnrollDevice;
use App\Data\Auth\DeviceSessionContext;
use App\Data\Devices\EnrollDeviceData;
use App\Exceptions\DeviceProofOfPossessionRequired;
use App\Models\Device;
use App\Services\SocialAuth\SocialUserPayload;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class PostgresSocialConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

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
}
