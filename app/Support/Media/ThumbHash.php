<?php

namespace App\Support\Media;

use InvalidArgumentException;

/**
 * ThumbHash encoder — a very compact representation of an image's overall look, ~25 bytes, meant
 * to be rendered as a blurred placeholder while the real bytes are still in flight.
 *
 * A port of Evan Wallace's reference implementation (MIT):
 * https://github.com/evanw/thumbhash — `rgbaToThumbHash` in `thumbhash.js`. There is no PHP
 * package for it, so it is vendored here rather than approximated. **Do not "improve" the maths.**
 * The Android client decodes these with its own port of the same format, so a
 * plausible-but-different encoder produces subtly wrong colours on every image in the product and
 * fails nowhere visible. Verified byte-for-byte against the reference across portrait, landscape,
 * square and single-axis shapes, with and without alpha — see ThumbHashTest.
 *
 * There is exactly one deliberate deviation, for uniform images, and it cannot change a decoded
 * pixel: see NEGLIGIBLE_SCALE and the comment in encodeChannel.
 *
 * Deliberately pure — arrays in, string out. No GD, no Storage, no models. That is what lets it be
 * checked against the reference at all, and what keeps the one piece that must be exactly right
 * away from the parts that merely have to work.
 *
 * Deliberately not behind a `Contracts\` interface either: that directory is for seams with
 * genuinely swappable implementations (OCR engines, perceptual hashers, safety analyzers).
 * ThumbHash is a fixed wire format with one correct answer.
 */
final class ThumbHash
{
    /** The reference implementation refuses anything larger; beyond this it is slow for no gain. */
    public const MAX_DIMENSION = 100;

    /** Below this a channel's variation is floating-point residue, not signal. See encodeChannel. */
    private const NEGLIGIBLE_SCALE = 1e-9;

    /**
     * @param  list<int>  $rgba  Row-major RGBA, four entries (0-255) per pixel, length 4*w*h.
     * @return string Raw hash bytes.
     */
    public static function encode(int $width, int $height, array $rgba): string
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("Refusing to encode a {$width}x{$height} image.");
        }

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new InvalidArgumentException(
                "{$width}x{$height} does not fit in ".self::MAX_DIMENSION.'x'.self::MAX_DIMENSION.'; scale it down first.'
            );
        }

        $pixels = $width * $height;

        if (count($rgba) !== $pixels * 4) {
            throw new InvalidArgumentException(
                'Expected '.($pixels * 4).' RGBA components for '."{$width}x{$height}, got ".count($rgba).'.'
            );
        }

        // Average colour, weighted by alpha so transparent pixels do not drag it toward black.
        $avgR = 0.0;
        $avgG = 0.0;
        $avgB = 0.0;
        $avgA = 0.0;

        for ($i = 0, $j = 0; $i < $pixels; $i++, $j += 4) {
            $alpha = $rgba[$j + 3] / 255;
            $avgR += $alpha / 255 * $rgba[$j];
            $avgG += $alpha / 255 * $rgba[$j + 1];
            $avgB += $alpha / 255 * $rgba[$j + 2];
            $avgA += $alpha;
        }

        if ($avgA > 0) {
            $avgR /= $avgA;
            $avgG /= $avgA;
            $avgB /= $avgA;
        }

        $hasAlpha = $avgA < $pixels;
        // Fewer luminance terms when alpha has to be encoded too, to keep the hash small.
        $lLimit = $hasAlpha ? 5 : 7;
        $longEdge = max($width, $height);
        $lx = max(1, self::jsRound($lLimit * $width / $longEdge));
        $ly = max(1, self::jsRound($lLimit * $height / $longEdge));

        $l = [];  // luminance
        $p = [];  // yellow - blue
        $q = [];  // red - green
        $a = [];  // alpha

        for ($i = 0, $j = 0; $i < $pixels; $i++, $j += 4) {
            $alpha = $rgba[$j + 3] / 255;
            $r = $avgR * (1 - $alpha) + $alpha / 255 * $rgba[$j];
            $g = $avgG * (1 - $alpha) + $alpha / 255 * $rgba[$j + 1];
            $b = $avgB * (1 - $alpha) + $alpha / 255 * $rgba[$j + 2];
            $l[] = ($r + $g + $b) / 3;
            $p[] = ($r + $g) / 2 - $b;
            $q[] = $r - $g;
            $a[] = $alpha;
        }

        [$lDc, $lAc, $lScale] = self::encodeChannel($l, max(3, $lx), max(3, $ly), $width, $height);
        [$pDc, $pAc, $pScale] = self::encodeChannel($p, 3, 3, $width, $height);
        [$qDc, $qAc, $qScale] = self::encodeChannel($q, 3, 3, $width, $height);
        [$aDc, $aAc, $aScale] = $hasAlpha
            ? self::encodeChannel($a, 5, 5, $width, $height)
            : [0.0, [], 0.0];

        $isLandscape = $width > $height;

        $header24 = self::jsRound(63 * $lDc)
            | (self::jsRound(31.5 + 31.5 * $pDc) << 6)
            | (self::jsRound(31.5 + 31.5 * $qDc) << 12)
            | (self::jsRound(31 * $lScale) << 18)
            | (($hasAlpha ? 1 : 0) << 23);

        $header16 = ($isLandscape ? $ly : $lx)
            | (self::jsRound(63 * $pScale) << 3)
            | (self::jsRound(63 * $qScale) << 9)
            | (($isLandscape ? 1 : 0) << 15);

        $hash = [
            $header24 & 255,
            ($header24 >> 8) & 255,
            $header24 >> 16,
            $header16 & 255,
            $header16 >> 8,
        ];

        if ($hasAlpha) {
            $hash[] = self::jsRound(15 * $aDc) | (self::jsRound(15 * $aScale) << 4);
        }

        $acStart = $hasAlpha ? 6 : 5;
        $acIndex = 0;
        $channels = $hasAlpha ? [$lAc, $pAc, $qAc, $aAc] : [$lAc, $pAc, $qAc];

        foreach ($channels as $ac) {
            foreach ($ac as $f) {
                $slot = $acStart + ($acIndex >> 1);
                $hash[$slot] = ($hash[$slot] ?? 0) | (self::jsRound(15 * $f) << (($acIndex & 1) << 2));
                $acIndex++;
            }
        }

        ksort($hash);

        return implode('', array_map(static fn (int $byte): string => chr($byte & 255), $hash));
    }

    /**
     * Base64 of {@see encode}, which is the form the API and the client exchange.
     *
     * @param  list<int>  $rgba
     */
    public static function encodeToBase64(int $width, int $height, array $rgba): string
    {
        return base64_encode(self::encode($width, $height, $rgba));
    }

    /**
     * DCT into one DC (constant) term and normalized AC (varying) terms.
     *
     * @param  list<float>  $channel
     * @return array{0: float, 1: list<float>, 2: float}
     */
    private static function encodeChannel(array $channel, int $nx, int $ny, int $w, int $h): array
    {
        $dc = 0.0;
        $ac = [];
        $scale = 0.0;
        $fx = [];

        for ($cy = 0; $cy < $ny; $cy++) {
            for ($cx = 0; $cx * $ny < $nx * ($ny - $cy); $cx++) {
                $f = 0.0;

                for ($x = 0; $x < $w; $x++) {
                    $fx[$x] = cos(M_PI / $w * $cx * ($x + 0.5));
                }

                for ($y = 0; $y < $h; $y++) {
                    $fy = cos(M_PI / $h * $cy * ($y + 0.5));

                    for ($x = 0; $x < $w; $x++) {
                        $f += $channel[$x + $y * $w] * $fx[$x] * $fy;
                    }
                }

                $f /= $w * $h;

                if ($cx || $cy) {
                    $ac[] = $f;
                    $scale = max($scale, abs($f));
                } else {
                    $dc = $f;
                }
            }
        }

        // The reference tests `if (scale)` — any non-zero. For a perfectly uniform channel the
        // true AC terms are 0 but floating point leaves residues around 1e-17, so `scale` is
        // "non-zero", and normalizing by it amplifies pure noise by ~1e16. The reference is
        // unstable there too: it disagrees with itself across engines, and this port disagreed
        // with it on exactly those inputs and no others.
        //
        // Treating a negligible scale as zero costs nothing and buys determinism. It cannot
        // affect any decoded image, because the scale is stored as round(31 * scale) — already 0
        // for anything this small — and the decoder multiplies every AC coefficient by it. The
        // threshold sits far below any real image's max |AC|, so genuine content is untouched.
        if ($scale > self::NEGLIGIBLE_SCALE) {
            foreach ($ac as $i => $value) {
                $ac[$i] = 0.5 + 0.5 / $scale * $value;
            }
        }

        return [$dc, $ac, $scale];
    }

    /**
     * JavaScript's `Math.round`, which rounds halves toward +infinity. PHP's `round()` rounds them
     * away from zero, so the two disagree on exactly the negative halves this format produces —
     * and a one-bit difference is a different hash.
     */
    private static function jsRound(float $value): int
    {
        return (int) floor($value + 0.5);
    }
}
