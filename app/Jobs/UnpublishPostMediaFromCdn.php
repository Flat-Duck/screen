<?php

namespace App\Jobs;

use App\Models\PostMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a media item's public copy — when its post is archived or deleted, when its author goes
 * private or is suspended, or when the reconciler notices any of those was missed.
 *
 * Rotates the token as well as deleting the objects. Deleting alone would leave the old URL able
 * to resolve again if the same media were ever republished; rotating means every URL ever handed
 * out for it is permanently dead.
 *
 * Be honest about the limit: once bytes have been public and cached at an edge, "unpublish" is
 * best-effort within a bounded window, and a copy already on someone's device is gone for good.
 * That is true of the signed path too, just over a shorter window.
 */
class UnpublishPostMediaFromCdn implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public readonly int $postMediaId)
    {
        $this->timeout = (int) config('social.media_job_timeout_seconds', 60);
        $this->onQueue('media');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(config('social.media_job_backoff_seconds', [30, 120, 300]));
    }

    public function handle(): void
    {
        $media = PostMedia::find($this->postMediaId);

        if (! $media || $media->public_token === null) {
            return;
        }

        $disk = Storage::disk((string) config('social.public_cdn.disk'));
        $urls = [];

        foreach (['original', 'thumbnail'] as $variant) {
            $path = $variant === 'thumbnail' ? $media->public_thumbnail_path : $media->public_path;

            if (! is_string($path) || $path === '') {
                continue;
            }

            $urls[] = $media->publicCdnUrl($variant);
            $disk->delete($path);
        }

        $media->forceFill([
            'public_token' => null,
            'public_path' => null,
            'public_thumbnail_path' => null,
            'published_publicly_at' => null,
        ])->save();

        $this->purgeEdge(array_values(array_filter($urls)));
    }

    /**
     * Deleting the origin object does not evict the CDN's copy — with a year-long max-age it would
     * serve indefinitely. Purging is therefore not optional decoration; without the credentials
     * configured, the deployment must instead bound its edge TTL and accept that window.
     *
     * @param  list<string>  $urls
     */
    private function purgeEdge(array $urls): void
    {
        $zone = config('social.public_cdn.purge.zone_id');
        $token = config('social.public_cdn.purge.token');

        if ($urls === [] || ! is_string($zone) || ! is_string($token) || $zone === '' || $token === '') {
            return;
        }

        $response = Http::withToken($token)
            ->connectTimeout(5)
            ->timeout(10)
            ->retry(2, 200)
            ->post("https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache", ['files' => $urls]);

        if ($response->failed()) {
            // Logged, not thrown: the database no longer points at these objects and the origin
            // copies are gone, so retrying the whole job would repeat deletes that already
            // happened. The reconciler is what catches a persistently failing edge.
            Log::warning('CDN purge failed for unpublished media.', [
                'post_media_id' => $this->postMediaId,
                'status' => $response->status(),
            ]);
        }
    }
}
