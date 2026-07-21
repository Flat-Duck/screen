<?php

namespace App\Services;

use App\Enums\SecurityOutboxStatus;
use App\Models\Device;
use App\Models\MediaAnalysisItem;
use App\Models\MediaCleanupTask;
use App\Models\OperationsHealthSnapshot;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Scopes\NotArchivedScope;
use App\Models\SecurityOutboxMessage;
use App\Services\Fcm\FcmClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OperationsHealthService
{
    public function capture(): OperationsHealthSnapshot
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('select 1')),
            'redis' => $this->redisCheck(),
            'storage' => $this->probe(function (): void {
                $path = '.health/operations-probe';
                Storage::disk(config('social.media_disk'))->put($path, (string) now()->timestamp);
                Storage::disk(config('social.media_disk'))->delete($path);
            }),
            'mail' => ['status' => in_array(config('mail.default'), ['array', 'log'], true) ? 'not_configured' : 'ok'],
            'fcm' => ['status' => app(FcmClient::class)->isConfigured() ? 'ok' : 'not_configured'],
        ];
        $queues = $this->queueBacklog();
        $failedQueues = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->select('queue', DB::raw('count(*) as total'))->groupBy('queue')->pluck('total', 'queue')->map(fn ($count): int => (int) $count)->all();
        $versions = Device::query()->whereNotNull('app_version_name')->where('last_seen_at', '>=', now()->subDays(30))
            ->select('app_version_name', DB::raw('count(*) as total'))->groupBy('app_version_name')->orderByDesc('total')->limit(10)->get()
            ->map(fn (Device $device): array => ['version' => $device->app_version_name, 'devices' => (int) $device->getAttribute('total')])->all();
        $metrics = [
            'queue_backlog' => $queues,
            'failed_jobs_24h' => $failedQueues,
            'security_outbox_backlog' => SecurityOutboxMessage::query()->whereIn('status', [SecurityOutboxStatus::Pending, SecurityOutboxStatus::Processing])->count(),
            'media_processing_failures' => PostMedia::query()->where(function ($query): void {
                $query->where('status', PostMedia::STATUS_FAILED)->orWhere('ocr_status', PostMedia::PROCESSING_FAILED)->orWhere('hash_status', PostMedia::PROCESSING_FAILED)->orWhere('safety_status', PostMedia::PROCESSING_FAILED);
            })->count()
                + MediaAnalysisItem::query()->where(function ($query): void {
                    $query->where('ocr_status', 'failed')->orWhere('safety_status', 'failed');
                })->count(),
            'cleanup_failures' => MediaCleanupTask::query()->where('status', 'failed')->count()
                + Post::withoutGlobalScope(NotArchivedScope::class)->withTrashed()->where('purge_status', 'failed')->count(),
            'storage_bytes' => (int) PostMedia::query()->sum('size_bytes') + (int) MediaAnalysisItem::query()->sum('size_bytes'),
            'app_versions' => $versions,
        ];
        $status = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'failed') ? 'degraded' : 'healthy';

        $snapshot = OperationsHealthSnapshot::create(compact('status', 'checks', 'metrics') + ['captured_at' => now()]);
        OperationsHealthSnapshot::query()->where('captured_at', '<', now()->subDays(30))->delete();

        return $snapshot;
    }

    /** @return array{status: string} */
    private function probe(callable $probe): array
    {
        try {
            $probe();

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'failed'];
        }
    }

    /** @return array{status: string} */
    private function redisCheck(): array
    {
        if (! in_array(config('cache.default'), ['redis'], true) && config('queue.default') !== 'redis') {
            return ['status' => 'not_configured'];
        }

        return $this->probe(fn () => Redis::connection()->ping());
    }

    /** @return array<string, int> */
    private function queueBacklog(): array
    {
        try {
            return collect(['default', 'security', 'media'])
                ->mapWithKeys(fn (string $queue): array => [$queue => Queue::connection()->size($queue)])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
