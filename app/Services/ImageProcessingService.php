<?php

namespace App\Services;

use App\Exceptions\PermanentRemoteImageException;
use App\Exceptions\TransientRemoteImageException;
use App\Support\Media\ThumbHash;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\MediaType;
use RuntimeException;
use Throwable;

/**
 * Decoding/encoding always goes through this service so the EXIF/GPS-stripping re-encode
 * (in storeOriginal()) is unconditional and can't be bypassed by a caller that "just wants
 * to save the file" — see the plan's privacy requirement.
 */
class ImageProcessingService
{
    private ImageManager $manager;

    public function __construct(private readonly ImageSafetyInspector $inspector)
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Decode, auto-orient, and re-encode an uploaded image — the re-encode is what strips
     * EXIF/GPS metadata, since Intervention's encoders don't carry source metadata into the
     * output. Stored under a generated UUID filename; the client's filename is never used.
     *
     * $maxDimension optionally scales down (never up) to fit a square box before encoding —
     * used for avatars, where there's no separate original/thumbnail pair to maintain.
     *
     * @return array{path: string, width: int, height: int, mime: string, size: int}
     */
    public function storeOriginal(
        UploadedFile $file,
        string $directory,
        ?int $maxDimension = null,
        ?string $diskName = null,
    ): array {
        $this->inspector->inspectLocalFile($file->getRealPath());
        $image = $this->manager->read($file->getRealPath())->orient();

        if ($maxDimension !== null) {
            $image->scaleDown($maxDimension, $maxDimension);
        }

        $mediaType = MediaType::create($file->getMimeType());
        $encoded = $image->encodeByMediaType($mediaType);
        $extension = $mediaType->fileExtension()->value;

        $path = sprintf('%s/%s.%s', $directory, (string) Str::uuid(), $extension);

        if (! Storage::disk($diskName ?? config('social.media_disk'))->put($path, (string) $encoded)) {
            throw new RuntimeException("Failed to store original image [{$path}].");
        }

        return [
            'path' => $path,
            'width' => $image->width(),
            'height' => $image->height(),
            'mime' => $encoded->mimetype(),
            'size' => $encoded->size(),
        ];
    }

    /**
     * Downloads a remote image (e.g. a Google/Facebook profile picture URL) and stores
     * it through the same orient/scale/re-encode pipeline as storeOriginal().
     *
     * @return array{path: string, width: int, height: int, mime: string, size: int}
     */
    public function storeFromUrl(string $url, string $directory, ?int $maxDimension = 512): array
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new PermanentRemoteImageException('Remote image URL must use HTTPS.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'remote-avatar-');
        if ($temporaryPath === false) {
            throw new TransientRemoteImageException('Could not allocate temporary avatar storage.');
        }

        $maxBytes = (int) config('social.images.remote_avatar_max_bytes');
        try {
            // The read timeout is already set at the end of this chain; this adds the connect
            // half, so a remote host that accepts nothing cannot hold the worker either.
            $response = Http::connectTimeout(3)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => (int) config('social.images.remote_avatar_max_redirects'),
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['https'],
                    ],
                    'sink' => $temporaryPath,
                    'on_headers' => function ($response) use ($maxBytes): void {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (int) $length > $maxBytes) {
                            throw new PermanentRemoteImageException('Remote image exceeds the byte limit.');
                        }
                    },
                    'progress' => function (int $downloadTotal, int $downloaded) use ($maxBytes): void {
                        if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) {
                            throw new PermanentRemoteImageException('Remote image exceeds the byte limit.');
                        }
                    },
                ])->timeout(10)->get($url);

            if ($response->serverError()) {
                throw new TransientRemoteImageException("Remote image server returned {$response->status()}.");
            }

            if ($response->clientError()) {
                throw new PermanentRemoteImageException("Remote image request was rejected with {$response->status()}.");
            }

            if (filesize($temporaryPath) > $maxBytes) {
                throw new PermanentRemoteImageException('Remote image exceeds the byte limit.');
            }

            try {
                $this->inspector->inspectLocalFile($temporaryPath);
                $image = $this->manager->read($temporaryPath)->orient();
            } catch (Throwable $exception) {
                throw new PermanentRemoteImageException('Remote image could not be decoded safely.', previous: $exception);
            }

            if ($maxDimension !== null) {
                $image->scaleDown($maxDimension, $maxDimension);
            }

            $encoded = $image->encode();
            $extension = MediaType::create($encoded->mimetype())->fileExtension()->value;
            $path = sprintf('%s/%s.%s', $directory, (string) Str::uuid(), $extension);

            if (! Storage::disk(config('social.media_disk'))->put($path, (string) $encoded)) {
                throw new TransientRemoteImageException("Failed to store remote image [{$path}].");
            }

            return [
                'path' => $path,
                'width' => $image->width(),
                'height' => $image->height(),
                'mime' => $encoded->mimetype(),
                'size' => $encoded->size(),
            ];
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * Scales the already-clean original down (never up) to fit within a maxDimension x
     * maxDimension box and writes a WebP thumbnail to the destination path. The thumbnail is
     * always written to the same disk the source lives on ($diskName, defaulting to the legacy
     * local media_disk) — an R2-backed original gets an R2-backed thumbnail alongside it, never
     * routed through the local disk (see docs/SECURITY.md §12).
     *
     * Returns what the decode revealed along the way. The original's dimensions and its ThumbHash
     * both need the image in memory, and this method is the only place in the media pipeline that
     * already has it — computing either anywhere else means a second fetch out of the object store
     * and a second decode, per image.
     *
     * @return array{width: int, height: int, thumbhash: string}
     */
    public function generateThumbnail(string $sourcePath, string $destinationPath, ?string $diskName = null, int $maxDimension = 640): array
    {
        $disk = Storage::disk($diskName ?? config('social.media_disk'));

        $this->inspector->inspectObject($disk, $sourcePath);

        $contents = $disk->get($sourcePath);
        $image = $this->manager->read($contents);

        // Read before scaleDown: these describe the original, which is what the client needs to
        // reserve layout space. scaleDown preserves aspect ratio, so the ratio is right for the
        // thumbnail too.
        $width = $image->width();
        $height = $image->height();
        // A second instance from bytes already in memory, not a clone: scaling for the
        // placeholder is destructive, and the 640px thumbnail still needs the full-size original.
        // Cheap next to the object-store fetch that got us here.
        $thumbhash = $this->placeholderFor($this->manager->read($contents));

        $encoded = $image->scaleDown($maxDimension, $maxDimension)->toWebp(quality: 75);

        if (! $disk->put($destinationPath, (string) $encoded)) {
            throw new RuntimeException("Failed to write thumbnail [{$destinationPath}].");
        }

        return ['width' => $width, 'height' => $height, 'thumbhash' => $thumbhash];
    }

    /**
     * The ThumbHash of an image already on disk, without touching its thumbnail.
     *
     * For backfilling rows that predate the placeholder column. Prefer pointing this at the
     * thumbnail rather than the original: both get scaled to at most
     * {@see ThumbHash::MAX_DIMENSION} before encoding, so the hash is equivalent, and a 640px
     * WebP is a small fraction of a full screenshot to pull out of the object store.
     */
    public function placeholderFrom(string $sourcePath, ?string $diskName = null): string
    {
        $disk = Storage::disk($diskName ?? config('social.media_disk'));

        $this->inspector->inspectObject($disk, $sourcePath);

        return $this->placeholderFor($this->manager->read($disk->get($sourcePath)));
    }

    /**
     * A ThumbHash of the image — ~25 bytes that decode to a blurred impression of it, shipped in
     * the feed JSON so a card can render something before any image byte arrives.
     *
     * Pixel extraction lives here rather than in {@see ThumbHash} to keep that class pure and
     * testable against the reference implementation, and because this service's whole job is
     * being the one place that decodes an image.
     *
     * Scales destructively, so hand it an instance nothing else still needs.
     */
    private function placeholderFor(ImageInterface $image): string
    {
        $small = $image->scaleDown(ThumbHash::MAX_DIMENSION, ThumbHash::MAX_DIMENSION);
        $width = $small->width();
        $height = $small->height();

        $native = $small->core()->native();

        // GD indexed images have no alpha channel to read; converting first keeps imagecolorat's
        // return value in the 0xAARRGGBB form unpacked below.
        if ($native instanceof GdImage && ! imageistruecolor($native)) {
            imagepalettetotruecolor($native);
        }

        $rgba = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $colour = imagecolorat($native, $x, $y);
                $rgba[] = ($colour >> 16) & 0xFF;
                $rgba[] = ($colour >> 8) & 0xFF;
                $rgba[] = $colour & 0xFF;
                // GD stores alpha as 0 (opaque) to 127 (transparent); ThumbHash wants 0-255 the
                // other way round.
                $rgba[] = 255 - (int) round((($colour >> 24) & 0x7F) * 255 / 127);
            }
        }

        return ThumbHash::encodeToBase64($width, $height, $rgba);
    }
}
