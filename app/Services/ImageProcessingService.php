<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\MediaType;

/**
 * Decoding/encoding always goes through this service so the EXIF/GPS-stripping re-encode
 * (in storeOriginal()) is unconditional and can't be bypassed by a caller that "just wants
 * to save the file" — see the plan's privacy requirement.
 */
class ImageProcessingService
{
    private ImageManager $manager;

    public function __construct()
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
    public function storeOriginal(UploadedFile $file, string $directory, ?int $maxDimension = null): array
    {
        $image = $this->manager->read($file->getRealPath())->orient();

        if ($maxDimension !== null) {
            $image->scaleDown($maxDimension, $maxDimension);
        }

        $mediaType = MediaType::create($file->getMimeType());
        $encoded = $image->encodeByMediaType($mediaType);
        $extension = $mediaType->fileExtension()->value;

        $path = sprintf('%s/%s.%s', $directory, (string) Str::uuid(), $extension);

        Storage::disk(config('social.media_disk'))->put($path, (string) $encoded);

        return [
            'path' => $path,
            'width' => $image->width(),
            'height' => $image->height(),
            'mime' => $encoded->mimetype(),
            'size' => $encoded->size(),
        ];
    }

    /**
     * Scales the already-clean original down (never up) to fit within a maxDimension x
     * maxDimension box and writes a WebP thumbnail to the destination path.
     */
    public function generateThumbnail(string $sourcePath, string $destinationPath, int $maxDimension = 640): void
    {
        $disk = Storage::disk(config('social.media_disk'));

        $encoded = $this->manager->read($disk->get($sourcePath))
            ->scaleDown($maxDimension, $maxDimension)
            ->toWebp(quality: 75);

        $disk->put($destinationPath, (string) $encoded);
    }
}
