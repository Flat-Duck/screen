<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extends the stock `/up` check with real dependency probes. Laravel 13's built-in health
 * check has no pluggable-check registry (it's just a static "app booted" view), so this is a
 * plain route rather than a customization of `/up` itself.
 *
 * Deliberately no new package (e.g. spatie/laravel-health) — three checks don't warrant one.
 *
 * Gated by a shared secret (`config('health.deep_check_secret')`) rather than a session/API
 * guard — an uptime monitor hitting this has no user to authenticate as, and this returns raw
 * exception messages plus the queue backlog count, which shouldn't be public. Fails closed
 * (404, not 401/403 — no hint the endpoint exists) if the secret is unset or doesn't match.
 */
class HealthCheckController extends Controller
{
    /** Above this many queued jobs, the `database`-driver queue worker is likely not running. */
    private const JOBS_BACKLOG_THRESHOLD = 500;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->isAuthorized($request), 404);

        $checks = [
            'database' => $this->checkDatabase(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
        ];

        $healthy = collect($checks)->every(fn (array $check): bool => $check['ok']);

        return response()->json([
            'status' => $healthy ? 'ok' : 'unhealthy',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function isAuthorized(Request $request): bool
    {
        $secret = (string) config('health.deep_check_secret');

        return $secret !== '' && hash_equals($secret, (string) $request->header('X-Health-Check-Secret', ''));
    }

    /** @return array{ok: bool, message?: string} */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, backlog?: int, message?: string} */
    private function checkQueue(): array
    {
        try {
            $backlog = DB::table('jobs')->count();

            return ['ok' => $backlog < self::JOBS_BACKLOG_THRESHOLD, 'backlog' => $backlog];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, message?: string} */
    private function checkStorage(): array
    {
        try {
            $disk = Storage::disk(config('social.media_disk'));
            $path = 'health-check.txt';

            $disk->put($path, (string) now()->timestamp);
            $disk->delete($path);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
