<?php

namespace Tests\Feature;

use App\Actions\Auth\CompleteSocialLogin;
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
                    app(CompleteSocialLogin::class)($payload, 'ci-worker');
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
}
