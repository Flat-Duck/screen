<?php

namespace App\Services\Screenshots;

use App\Contracts\PerceptualHasher;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DifferenceHashService implements PerceptualHasher
{
    public function hash(string $path): string
    {
        $source = imagecreatefromstring(Storage::disk(config('social.media_disk'))->get($path));
        if ($source === false) {
            throw new RuntimeException('Screenshot could not be decoded for duplicate detection.');
        }

        $sample = imagecreatetruecolor(9, 8);
        if (! imagecopyresampled($sample, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source))) {
            imagedestroy($source);
            imagedestroy($sample);
            throw new RuntimeException('Screenshot could not be sampled for duplicate detection.');
        }

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= $this->luminance($this->pixelColor($sample, $x, $y))
                    > $this->luminance($this->pixelColor($sample, $x + 1, $y)) ? '1' : '0';
            }
        }

        imagedestroy($source);
        imagedestroy($sample);

        return implode('', array_map(
            static fn (string $nibble): string => dechex((int) bindec($nibble)),
            str_split($bits, 4),
        ));
    }

    public function version(): string
    {
        return 'dhash-64-v1';
    }

    private function luminance(int $color): int
    {
        $red = ($color >> 16) & 0xFF;
        $green = ($color >> 8) & 0xFF;
        $blue = $color & 0xFF;

        return (int) round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));
    }

    private function pixelColor(GdImage $image, int $x, int $y): int
    {
        $color = imagecolorat($image, $x, $y);
        if ($color === false) {
            throw new RuntimeException('Screenshot pixel data could not be read for duplicate detection.');
        }

        return $color;
    }
}
