<?php

namespace App\Support\Media;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses how an authorized media byte-stream actually reaches the viewer.
 *
 * Authorization always happens in the calling controller, on every request — this class only
 * decides who transfers the bytes afterwards. Streaming them through PHP holds an FPM worker for
 * the full duration of a transfer that is mostly network wait, so a feed screen full of images
 * can occupy the entire worker pool on a small box while doing no real work. When the backing
 * disk can mint its own presigned URL (S3/R2), we hand the viewer a short-lived redirect instead
 * and the object store serves the bytes directly.
 *
 * The trade is revocation latency: a presigned URL stays valid for its own lifetime even if the
 * viewer loses access a moment after being redirected. That window is deliberately much shorter
 * than the capability URL that produced it — see `social.media_offload_ttl_seconds`. Streaming
 * remains the fallback for disks without presigning (local development and tests).
 */
final class MediaDelivery
{
    /**
     * @param  array<string, string>  $headers
     * @param  string|null  $etag  A strong validator for these bytes, unquoted. Media is immutable
     *                             per (record, variant), so callers can build one from ids and a
     *                             timestamp without touching the object store.
     */
    public static function respond(
        FilesystemAdapter $disk,
        string $path,
        string $cacheControl,
        array $headers = [],
        ?string $etag = null,
    ): Response {
        if ($etag !== null && self::matchesIfNoneMatch($etag)) {
            // Answered before anything else happens — in particular before minting a presigned
            // URL, which is the whole point of having a validator on the offload path.
            return response('', Response::HTTP_NOT_MODIFIED, [
                'ETag' => '"'.$etag.'"',
                'Cache-Control' => $cacheControl,
            ]);
        }

        if ($etag !== null) {
            $headers['ETag'] = '"'.$etag.'"';
        }

        if (self::shouldOffload($disk)) {
            return self::offload($disk, $path, $cacheControl, $etag);
        }

        // Only the streaming branch needs to know whether the object is there. On an object store
        // this is a network HEAD on the critical path of every single image, asking a question the
        // presigned URL already answers for free — a missing object 404s at the store, with the
        // same result for the viewer. On a local disk it is a stat, so it stays here.
        abort_unless($disk->exists($path), Response::HTTP_NOT_FOUND);

        return $disk->response($path, null, array_merge($headers, [
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    /**
     * Deliberately exact rather than weak-comparing or honouring `*`: these validators are
     * generated, not client-supplied, and anything we did not mint should be treated as a miss.
     */
    private static function matchesIfNoneMatch(string $etag): bool
    {
        $header = request()->headers->get('If-None-Match');

        if ($header === null || $header === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            if (trim(trim($candidate), '"') === $etag) {
                return true;
            }
        }

        return false;
    }

    private static function shouldOffload(FilesystemAdapter $disk): bool
    {
        // A local disk can also mint temporary URLs, but they point back at this same application,
        // so redirecting to one costs an extra round trip and still streams through PHP. Offloading
        // is only worth anything when the bytes come from somewhere that isn't us.
        return (bool) config('social.media_offload_enabled', true)
            && ! $disk instanceof LocalFilesystemAdapter
            && $disk->providesTemporaryUrls();
    }

    private static function offload(
        FilesystemAdapter $disk,
        string $path,
        string $cacheControl,
        ?string $etag = null,
    ): RedirectResponse {
        $ttl = max(1, (int) config('social.media_offload_ttl_seconds', 120));
        $target = $disk->temporaryUrl($path, now()->addSeconds($ttl));

        // The Location header carries a bearer capability for the object, so this redirect is
        // never publicly cacheable regardless of how cacheable the underlying media is. Media
        // that was already uncacheable stays no-store so each view is reauthorized; otherwise the
        // viewer's own browser may reuse the redirect until the presigned URL expires anyway.
        $redirectCacheControl = str_contains($cacheControl, 'no-store')
            ? 'no-store, private'
            : 'private, max-age='.$ttl;

        $headers = [
            'Cache-Control' => $redirectCacheControl,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($etag !== null) {
            $headers['ETag'] = '"'.$etag.'"';
        }

        return redirect()->away($target, 302, $headers);
    }
}
