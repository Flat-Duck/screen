<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extends the stock `/up` check with real dependency probes. Laravel 13's built-in health
 * check has no pluggable-check registry (it's just a static "app booted" view), so this is a
 * plain ungated route rather than a customization of `/up` itself.
 *
 * Deliberately no new package (e.g. spatie/laravel-health) — three checks don't warrant one.
 */
class HealthCheckController extends Controller
{
    /** Above this many queued jobs, the `database`-driver queue worker is likely not running. */
    private const JOBS_BACKLOG_THRESHOLD = 500;

    public function __invoke(): JsonResponse
    {
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
