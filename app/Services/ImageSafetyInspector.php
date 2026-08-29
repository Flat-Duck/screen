<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;

class ImageSafetyInspector
{
    /** @return array{width: int, height: int, mime: string, size: int, sha256: string} */
    public function inspectObject(FilesystemAdapter $disk, string $path): array
    {
        $source = $disk->readStream($path);
        if (! is_resource($source)) {
            throw new InvalidArgumentException('The uploaded object could not be read.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'image-inspection-');
        $temporary = $temporaryPath === false ? false : fopen($temporaryPath, 'w+b');
        if ($temporaryPath === false || $temporary === false) {
            fclose($source);
            throw new \RuntimeException('Could not allocate temporary image storage.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false) {
                    throw new InvalidArgumentException('The uploaded object could not be read.');
                }
                $size += strlen($chunk);
                if ($size > (int) config('social.uploads.max_size_bytes')) {
                    throw new InvalidArgumentException('The uploaded object exceeds the byte limit.');
                }
                hash_update($hash, $chunk);
                fwrite($temporary, $chunk);
            }

            $inspected = $this->inspectLocalFile($temporaryPath);

            return [...$inspected, 'size' => $size, 'sha256' => hash_final($hash)];
        } finally {
            fclose($source);
            fclose($temporary);
            @unlink($temporaryPath);
        }
    }

    /** @return array{width: int, height: int, mime: string} */
    public function inspectLocalFile(string $path): array
    {
        $details = @getimagesize($path);
        if ($details === false) {
            throw new InvalidArgumentException('The file is not a decodable image.');
        }

        [$width, $height] = $details;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime) || ! in_array($mime, config('social.uploads.allowed_mime_types'), true)) {
            throw new InvalidArgumentException('The image format is not allowed.');
        }
        if ($width < 1 || $height < 1
            || $width > (int) config('social.images.max_dimension')
            || $height > (int) config('social.images.max_dimension')
            || $width * $height > (int) config('social.images.max_pixels')) {
            throw new InvalidArgumentException('The image dimensions exceed the safety limit.');
        }

        return ['width' => $width, 'height' => $height, 'mime' => $mime];
    }
}
